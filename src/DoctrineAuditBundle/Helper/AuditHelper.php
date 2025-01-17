<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

class AuditHelper
{
    /**
     * @var \DH\DoctrineAuditBundle\AuditConfiguration
     */
    private $configuration;

    /**
     * @param AuditConfiguration $configuration
     */
    public function __construct(AuditConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return \DH\DoctrineAuditBundle\AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Returns the primary key value of an entity.
     *
     * @param EntityManager $em
     * @param object        $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return mixed
     */
    public function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $pk = $meta->getSingleIdentifierFieldName();

        if (isset($meta->fieldMappings[$pk])) {
            $type = Type::getType($meta->fieldMappings[$pk]['type']);

            return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
        }

        // Primary key is not part of fieldMapping
        // @see https://github.com/DamienHarper/DoctrineAuditBundle/issues/40
        // @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
        // We try to get it from associationMapping (will throw a MappingException if not available)
        $targetEntity = $meta->getReflectionProperty($pk)->getValue($entity);

        $mapping = $meta->getAssociationMapping($pk);
        $meta = $em->getClassMetadata($mapping['targetEntity']);
        $pk = $meta->getSingleIdentifierFieldName();
        $type = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($targetEntity));
    }

    /**
     * Computes a usable diff.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function diff(EntityManager $em, $entity, array $ch): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $diff = [];

        foreach ($ch as $fieldName => list($old, $new)) {
            $o = null;
            $n = null;

            if (
                $meta->hasField($fieldName) &&
                !isset($meta->embeddedClasses[$fieldName]) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $mapping = $meta->fieldMappings[$fieldName];
                $type = Type::getType($mapping['type']);
                $o = $this->value($em, $type, $old);
                $n = $this->value($em, $type, $new);
            } elseif (
                $meta->hasAssociation($fieldName) &&
                $meta->isSingleValuedAssociation($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $o = $this->summarize($em, $old);
                $n = $this->summarize($em, $new);
            }

            if ($o !== $n) {
                $diff[$fieldName] = [
                    'old' => $o,
                    'new' => $n,
                ];
            }
        }

        return $diff;
    }

    /**
     * Type converts the input value and returns it.
     *
     * @param EntityManager $em
     * @param Type          $type
     * @param mixed         $value
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return mixed
     */
    private function value(EntityManager $em, Type $type, $value)
    {
        if (null === $value) {
            return null;
        }

        $platform = $em->getConnection()->getDatabasePlatform();

        switch ($type->getName()) {
            case Type::DECIMAL:
            case Type::BIGINT:
                $convertedValue = (string) $value;

                break;
            case Type::INTEGER:
            case Type::SMALLINT:
                $convertedValue = (int) $value;

                break;
            case Type::FLOAT:
            case Type::BOOLEAN:
                $convertedValue = $type->convertToPHPValue($value, $platform);

                break;
            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }

    /**
     * Blames an audit operation.
     *
     * @return array
     */
    public function blame(): array
    {
        $user_id = null;
        $username = null;
        $client_ip = null;

        $request = $this->configuration->getRequestStack()->getCurrentRequest();
        if (null !== $request) {
            $client_ip = $request->getClientIp();
        }

        $user = $this->configuration->getUserProvider()->getUser();
        if ($user instanceof UserInterface) {
            $user_id = $user->getId();
            $username = $user->getUsername();
        }

        return [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
        ];
    }

    /**
     * Returns an array describing an entity.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param mixed         $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function summarize(EntityManager $em, $entity = null, $id = null): ?array
    {
        if (null === $entity) {
            return null;
        }

        $em->getUnitOfWork()->initializeObject($entity); // ensure that proxies are initialized
        $meta = $em->getClassMetadata(\get_class($entity));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $id ?? $this->id($em, $entity);

        if (method_exists($entity, '__toString')) {
            $label = (string) $entity;
        } else {
            $label = \get_class($entity).'#'.$pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->table['name'],
            $pkName => $pkValue,
        ];
    }
}
