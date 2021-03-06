<?php

namespace Doctrine\Tests\ORM\Mapping;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Events;

require_once __DIR__ . '/../../TestInit.php';
require_once __DIR__ . '/../../Models/Global/GlobalNamespaceModel.php';

class ClassMetadataTest extends \Doctrine\Tests\OrmTestCase
{
    public function testClassMetadataInstanceSerialization()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        // Test initial state
        $this->assertTrue(count($cm->getReflectionProperties()) == 0);
        $this->assertInstanceOf('ReflectionClass', $cm->reflClass);
        $this->assertEquals('Doctrine\Tests\Models\CMS\CmsUser', $cm->name);
        $this->assertEquals('Doctrine\Tests\Models\CMS\CmsUser', $cm->rootEntityName);
        $this->assertEquals(array(), $cm->subClasses);
        $this->assertEquals(array(), $cm->parentClasses);
        $this->assertEquals(ClassMetadata::INHERITANCE_TYPE_NONE, $cm->inheritanceType);

        // Customize state
        $cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $cm->setSubclasses(array("One", "Two", "Three"));
        $cm->setParentClasses(array("UserParent"));
        $cm->setCustomRepositoryClass("UserRepository");
        $cm->setDiscriminatorColumn(array('name' => 'disc', 'type' => 'integer'));
        $cm->mapOneToOne(array('fieldName' => 'phonenumbers', 'targetEntity' => 'CmsAddress', 'mappedBy' => 'foo'));
        $cm->markReadOnly();
        $cm->addNamedQuery(array('name' => 'dql', 'query' => 'foo'));
        $this->assertEquals(1, count($cm->associationMappings));

        $serialized = serialize($cm);
        $cm = unserialize($serialized);
        $cm->wakeupReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        // Check state
        $this->assertTrue(count($cm->getReflectionProperties()) > 0);
        $this->assertEquals('Doctrine\Tests\Models\CMS', $cm->namespace);
        $this->assertInstanceOf('ReflectionClass', $cm->reflClass);
        $this->assertEquals('Doctrine\Tests\Models\CMS\CmsUser', $cm->name);
        $this->assertEquals('UserParent', $cm->rootEntityName);
        $this->assertEquals(array('Doctrine\Tests\Models\CMS\One', 'Doctrine\Tests\Models\CMS\Two', 'Doctrine\Tests\Models\CMS\Three'), $cm->subClasses);
        $this->assertEquals(array('UserParent'), $cm->parentClasses);
        $this->assertEquals('Doctrine\Tests\Models\CMS\UserRepository', $cm->customRepositoryClassName);
        $this->assertEquals(array('name' => 'disc', 'type' => 'integer', 'fieldName' => 'disc'), $cm->discriminatorColumn);
        $this->assertTrue($cm->associationMappings['phonenumbers']['type'] == ClassMetadata::ONE_TO_ONE);
        $this->assertEquals(1, count($cm->associationMappings));
        $oneOneMapping = $cm->getAssociationMapping('phonenumbers');
        $this->assertTrue($oneOneMapping['fetch'] == ClassMetadata::FETCH_LAZY);
        $this->assertEquals('phonenumbers', $oneOneMapping['fieldName']);
        $this->assertEquals('Doctrine\Tests\Models\CMS\CmsAddress', $oneOneMapping['targetEntity']);
        $this->assertTrue($cm->isReadOnly);
        $this->assertEquals(array('dql' => array('name'=>'dql','query'=>'foo','dql'=>'foo')), $cm->namedQueries);
    }

    public function testFieldIsNullable()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        // Explicit Nullable
        $cm->mapField(array('fieldName' => 'status', 'nullable' => true, 'type' => 'string', 'length' => 50));
        $this->assertTrue($cm->isNullable('status'));

        // Explicit Not Nullable
        $cm->mapField(array('fieldName' => 'username', 'nullable' => false, 'type' => 'string', 'length' => 50));
        $this->assertFalse($cm->isNullable('username'));

        // Implicit Not Nullable
        $cm->mapField(array('fieldName' => 'name', 'type' => 'string', 'length' => 50));
        $this->assertFalse($cm->isNullable('name'), "By default a field should not be nullable.");
    }

    /**
     * @group DDC-115
     */
    public function testMapAssocationInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_Article');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->mapManyToMany(array(
            'fieldName' => 'author',
            'targetEntity' => 'DoctrineGlobal_User',
            'joinTable' => array(
                'name' => 'bar',
                'joinColumns' => array(array('name' => 'bar_id', 'referencedColumnName' => 'id')),
                'inverseJoinColumns' => array(array('name' => 'baz_id', 'referencedColumnName' => 'id')),
            ),
        ));

        $this->assertEquals("DoctrineGlobal_User", $cm->associationMappings['author']['targetEntity']);
    }

    public function testMapManyToManyJoinTableDefaults()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->mapManyToMany(
            array(
            'fieldName' => 'groups',
            'targetEntity' => 'CmsGroup'
        ));

        $assoc = $cm->associationMappings['groups'];
        //$this->assertInstanceOf('Doctrine\ORM\Mapping\ManyToManyMapping', $assoc);
        $this->assertEquals(array(
            'name' => 'cmsuser_cmsgroup',
            'joinColumns' => array(array('name' => 'cmsuser_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE')),
            'inverseJoinColumns' => array(array('name' => 'cmsgroup_id', 'referencedColumnName' => 'id', 'onDelete' => 'CASCADE'))
        ), $assoc['joinTable']);
        $this->assertTrue($assoc['isOnDeleteCascade']);
    }

    public function testSerializeManyToManyJoinTableCascade()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->mapManyToMany(
            array(
            'fieldName' => 'groups',
            'targetEntity' => 'CmsGroup'
        ));

        /* @var $assoc \Doctrine\ORM\Mapping\ManyToManyMapping */
        $assoc = $cm->associationMappings['groups'];
        $assoc = unserialize(serialize($assoc));

        $this->assertTrue($assoc['isOnDeleteCascade']);
    }

    /**
     * @group DDC-115
     */
    public function testSetDiscriminatorMapInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->setDiscriminatorMap(array('descr' => 'DoctrineGlobal_Article', 'foo' => 'DoctrineGlobal_User'));

        $this->assertEquals("DoctrineGlobal_Article", $cm->discriminatorMap['descr']);
        $this->assertEquals("DoctrineGlobal_User", $cm->discriminatorMap['foo']);
    }

    /**
     * @group DDC-115
     */
    public function testSetSubClassesInGlobalNamespace()
    {
        require_once __DIR__."/../../Models/Global/GlobalNamespaceModel.php";

        $cm = new ClassMetadata('DoctrineGlobal_User');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->setSubclasses(array('DoctrineGlobal_Article'));

        $this->assertEquals("DoctrineGlobal_Article", $cm->subClasses[0]);
    }

    /**
     * @group DDC-268
     */
    public function testSetInvalidVersionMapping_ThrowsException()
    {
        $field = array();
        $field['fieldName'] = 'foo';
        $field['type'] = 'string';

        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->setVersionMapping($field);
    }

    public function testGetSingleIdentifierFieldName_MultipleIdentifierEntity_ThrowsException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->isIdentifierComposite  = true;

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->getSingleIdentifierFieldName();
    }

    public function testDuplicateAssociationMappingException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $a1 = array('fieldName' => 'foo', 'sourceEntity' => 'stdClass', 'targetEntity' => 'stdClass', 'mappedBy' => 'foo');
        $a2 = array('fieldName' => 'foo', 'sourceEntity' => 'stdClass', 'targetEntity' => 'stdClass', 'mappedBy' => 'foo');

        $cm->addInheritedAssociationMapping($a1);
        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->addInheritedAssociationMapping($a2);
    }

    public function testDuplicateColumnName_ThrowsMappingException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapField(array('fieldName' => 'name', 'columnName' => 'name'));

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->mapField(array('fieldName' => 'username', 'columnName' => 'name'));
    }

    public function testDuplicateColumnName_DiscriminatorColumn_ThrowsMappingException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapField(array('fieldName' => 'name', 'columnName' => 'name'));

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->setDiscriminatorColumn(array('name' => 'name'));
    }

    public function testDuplicateColumnName_DiscriminatorColumn2_ThrowsMappingException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->setDiscriminatorColumn(array('name' => 'name'));

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->mapField(array('fieldName' => 'name', 'columnName' => 'name'));
    }

    public function testDuplicateFieldAndAssocationMapping1_ThrowsException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapField(array('fieldName' => 'name', 'columnName' => 'name'));

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->mapOneToOne(array('fieldName' => 'name', 'targetEntity' => 'CmsUser'));
    }

    public function testDuplicateFieldAndAssocationMapping2_ThrowsException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapOneToOne(array('fieldName' => 'name', 'targetEntity' => 'CmsUser'));

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');
        $cm->mapField(array('fieldName' => 'name', 'columnName' => 'name'));
    }

    /**
     * @group DDC-1224
     */
    public function testGetTemporaryTableNameSchema()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->setTableName('foo.bar');

        $this->assertEquals('foo_bar_id_tmp', $cm->getTemporaryIdTableName());
    }

    public function testDefaultTableName()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        // When table's name is not given
        $primaryTable = array();
        $cm->setPrimaryTable($primaryTable);

        $this->assertEquals('CmsUser', $cm->getTableName());
        $this->assertEquals('CmsUser', $cm->table['name']);

        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        // When joinTable's name is not given
        $cm->mapManyToMany(array(
            'fieldName' => 'user',
            'targetEntity' => 'CmsUser',
            'inversedBy' => 'users',
            'joinTable' => array('joinColumns' => array(array('referencedColumnName' => 'id')),
                                 'inverseJoinColumns' => array(array('referencedColumnName' => 'id')))));
        $this->assertEquals('cmsaddress_cmsuser', $cm->associationMappings['user']['joinTable']['name']);
    }

    public function testDefaultJoinColumnName()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        // this is really dirty, but it's the simpliest way to test whether
        // joinColumn's name will be automatically set to user_id
        $cm->mapOneToOne(array(
            'fieldName' => 'user',
            'targetEntity' => 'CmsUser',
            'joinColumns' => array(array('referencedColumnName' => 'id'))));
        $this->assertEquals('user_id', $cm->associationMappings['user']['joinColumns'][0]['name']);

        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->mapManyToMany(array(
            'fieldName' => 'user',
            'targetEntity' => 'CmsUser',
            'inversedBy' => 'users',
            'joinTable' => array('name' => 'user_CmsUser',
                                'joinColumns' => array(array('referencedColumnName' => 'id')),
                                'inverseJoinColumns' => array(array('referencedColumnName' => 'id')))));
        $this->assertEquals('cmsaddress_id', $cm->associationMappings['user']['joinTable']['joinColumns'][0]['name']);
        $this->assertEquals('cmsuser_id', $cm->associationMappings['user']['joinTable']['inverseJoinColumns'][0]['name']);
    }

    /**
     * @group DDC-559
     */
    public function testUnderscoreNamingStrategyDefaults()
    {
        $namingStrategy     = new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_UPPER);
        $oneToOneMetadata   = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress', $namingStrategy);
        $manyToManyMetadata = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsAddress', $namingStrategy);

        $oneToOneMetadata->mapOneToOne(array(
            'fieldName'     => 'user',
            'targetEntity'  => 'CmsUser'
        ));

        $manyToManyMetadata->mapManyToMany(array(
            'fieldName'     => 'user',
            'targetEntity'  => 'CmsUser'
        ));
        
        $this->assertEquals(array('USER_ID'=>'ID'), $oneToOneMetadata->associationMappings['user']['sourceToTargetKeyColumns']);
        $this->assertEquals(array('USER_ID'=>'USER_ID'), $oneToOneMetadata->associationMappings['user']['joinColumnFieldNames']);
        $this->assertEquals(array('ID'=>'USER_ID'), $oneToOneMetadata->associationMappings['user']['targetToSourceKeyColumns']);

        $this->assertEquals('USER_ID', $oneToOneMetadata->associationMappings['user']['joinColumns'][0]['name']);
        $this->assertEquals('ID', $oneToOneMetadata->associationMappings['user']['joinColumns'][0]['referencedColumnName']);

        
        $this->assertEquals('CMS_ADDRESS_CMS_USER', $manyToManyMetadata->associationMappings['user']['joinTable']['name']);

        $this->assertEquals(array('CMS_ADDRESS_ID','CMS_USER_ID'), $manyToManyMetadata->associationMappings['user']['joinTableColumns']);
        $this->assertEquals(array('CMS_ADDRESS_ID'=>'ID'), $manyToManyMetadata->associationMappings['user']['relationToSourceKeyColumns']);
        $this->assertEquals(array('CMS_USER_ID'=>'ID'), $manyToManyMetadata->associationMappings['user']['relationToTargetKeyColumns']);

        $this->assertEquals('CMS_ADDRESS_ID', $manyToManyMetadata->associationMappings['user']['joinTable']['joinColumns'][0]['name']);
        $this->assertEquals('CMS_USER_ID', $manyToManyMetadata->associationMappings['user']['joinTable']['inverseJoinColumns'][0]['name']);

        $this->assertEquals('ID', $manyToManyMetadata->associationMappings['user']['joinTable']['joinColumns'][0]['referencedColumnName']);
        $this->assertEquals('ID', $manyToManyMetadata->associationMappings['user']['joinTable']['inverseJoinColumns'][0]['referencedColumnName']);


        $cm = new ClassMetadata('DoctrineGlobal_Article', $namingStrategy);
        $cm->mapManyToMany(array('fieldName' => 'author', 'targetEntity' => 'Doctrine\Tests\Models\CMS\CmsUser'));
        $this->assertEquals('DOCTRINE_GLOBAL_ARTICLE_CMS_USER', $cm->associationMappings['author']['joinTable']['name']);
    }

    /**
     * @group DDC-886
     */
    public function testSetMultipleIdentifierSetsComposite()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapField(array('fieldName' => 'name'));
        $cm->mapField(array('fieldName' => 'username'));

        $cm->setIdentifier(array('name', 'username'));
        $this->assertTrue($cm->isIdentifierComposite);
    }

    /**
     * @group DDC-944
     */
    public function testMappingNotFound()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException', "No mapping found for field 'foo' on class 'Doctrine\Tests\Models\CMS\CmsUser'.");
        $cm->getFieldMapping('foo');
    }

    /**
     * @group DDC-961
     */
    public function testJoinTableMappingDefaults()
    {
        $cm = new ClassMetadata('DoctrineGlobal_Article');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapManyToMany(array('fieldName' => 'author', 'targetEntity' => 'Doctrine\Tests\Models\CMS\CmsUser'));

        $this->assertEquals('doctrineglobal_article_cmsuser', $cm->associationMappings['author']['joinTable']['name']);
    }

    /**
     * @group DDC-117
     */
    public function testMapIdentifierAssociation()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\DDC117\DDC117ArticleDetails');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapOneToOne(array(
            'fieldName' => 'article',
            'id' => true,
            'targetEntity' => 'Doctrine\Tests\Models\DDC117\DDC117Article',
            'joinColumns' => array(),
        ));

        $this->assertTrue($cm->containsForeignIdentifier, "Identifier Association should set 'containsForeignIdentifier' boolean flag.");
        $this->assertEquals(array("article"), $cm->identifier);
    }

    /**
     * @group DDC-117
     */
    public function testOrphanRemovalIdentifierAssociation()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\DDC117\DDC117ArticleDetails');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException', 'The orphan removal option is not allowed on an association that');
        $cm->mapOneToOne(array(
            'fieldName' => 'article',
            'id' => true,
            'targetEntity' => 'Doctrine\Tests\Models\DDC117\DDC117Article',
            'orphanRemoval' => true,
            'joinColumns' => array(),
        ));
    }

    /**
     * @group DDC-117
     */
    public function testInverseIdentifierAssocation()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\DDC117\DDC117ArticleDetails');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException', 'An inverse association is not allowed to be identifier in');
        $cm->mapOneToOne(array(
            'fieldName' => 'article',
            'id' => true,
            'mappedBy' => 'details', // INVERSE!
            'targetEntity' => 'Doctrine\Tests\Models\DDC117\DDC117Article',
            'joinColumns' => array(),
        ));
    }

    /**
     * @group DDC-117
     */
    public function testIdentifierAssocationManyToMany()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\DDC117\DDC117ArticleDetails');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException', 'Many-to-many or one-to-many associations are not allowed to be identifier in');
        $cm->mapManyToMany(array(
            'fieldName' => 'article',
            'id' => true,
            'targetEntity' => 'Doctrine\Tests\Models\DDC117\DDC117Article',
            'joinColumns' => array(),
        ));
    }

    /**
     * @group DDC-996
     */
    public function testEmptyFieldNameThrowsException()
    {
        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException',
            "The field or association mapping misses the 'fieldName' attribute in entity 'Doctrine\Tests\Models\CMS\CmsUser'.");
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $cm->mapField(array('fieldName' => ''));
    }

    public function testRetrievalOfNamedQueries()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $this->assertEquals(0, count($cm->getNamedQueries()));

        $cm->addNamedQuery(array(
            'name'  => 'userById',
            'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
        ));

        $this->assertEquals(1, count($cm->getNamedQueries()));
    }

    public function testExistanceOfNamedQuery()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $cm->addNamedQuery(array(
            'name'  => 'all',
            'query' => 'SELECT u FROM __CLASS__ u'
        ));

        $this->assertTrue($cm->hasNamedQuery('all'));
        $this->assertFalse($cm->hasNamedQuery('userById'));
    }

    public function testRetrieveOfNamedQuery()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $cm->addNamedQuery(array(
            'name'  => 'userById',
            'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
        ));

        $this->assertEquals('SELECT u FROM Doctrine\Tests\Models\CMS\CmsUser u WHERE u.id = ?1', $cm->getNamedQuery('userById'));
    }

    public function testNamingCollisionNamedQueryShouldThrowException()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);


        $this->setExpectedException('Doctrine\ORM\Mapping\MappingException');

        $cm->addNamedQuery(array(
            'name'  => 'userById',
            'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
        ));

        $cm->addNamedQuery(array(
            'name'  => 'userById',
            'query' => 'SELECT u FROM __CLASS__ u WHERE u.id = ?1'
        ));
    }

    /**
     * @group DDC-1068
     */
    public function testClassCaseSensitivity()
    {
        $user = new \Doctrine\Tests\Models\CMS\CmsUser();
        $cm = new ClassMetadata('DOCTRINE\TESTS\MODELS\CMS\CMSUSER');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);

        $this->assertEquals('Doctrine\Tests\Models\CMS\CmsUser', $cm->name);
    }

    /**
     * @group DDC-659
     */
    public function testLifecycleCallbackNotFound()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->addLifecycleCallback('notfound', 'postLoad');

        $this->setExpectedException("Doctrine\ORM\Mapping\MappingException", "Entity 'Doctrine\Tests\Models\CMS\CmsUser' has no method 'notfound' to be registered as lifecycle callback.");
        $cm->validateLifecycleCallbacks(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
    }

    /**
     * @group ImproveErrorMessages
     */
    public function testTargetEntityNotFound()
    {
        $cm = new ClassMetadata('Doctrine\Tests\Models\CMS\CmsUser');
        $cm->initializeReflection(new \Doctrine\Common\Persistence\Mapping\RuntimeReflectionService);
        $cm->mapManyToOne(array('fieldName' => 'address', 'targetEntity' => 'UnknownClass'));

        $this->setExpectedException("Doctrine\ORM\Mapping\MappingException", "The target-entity Doctrine\Tests\Models\CMS\UnknownClass cannot be found in 'Doctrine\Tests\Models\CMS\CmsUser#address'.");
        $cm->validateAssocations();
    }
}
