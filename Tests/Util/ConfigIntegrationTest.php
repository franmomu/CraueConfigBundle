<?php

namespace Craue\ConfigBundle\Tests\Util;

use Craue\ConfigBundle\Entity\Setting;
use Craue\ConfigBundle\Tests\IntegrationTestBundle\Entity\CustomSetting;
use Craue\ConfigBundle\Tests\IntegrationTestBundle\Util\CustomConfig;
use Craue\ConfigBundle\Tests\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * @group integration
 *
 * @author Christian Raue <christian.raue@gmail.com>
 * @copyright 2011-2023 Christian Raue
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class ConfigIntegrationTest extends IntegrationTestCase {

	/**
	 * Ensure that the code works with a real (i.e. not mocked) entity manager.
	 *
	 * @dataProvider getPlatformConfigs
	 */
	public function testWithRealEntityManager($platform, $config, $requiredExtension) {
		$this->initClient($requiredExtension, ['environment' => $platform, 'config' => $config]);

		$this->persistSetting(Setting::create('name1'));
		$this->persistSetting(Setting::create('name2'));

		$c = $this->getService('craue_config');

		$c->set('name1', 'value1');
		$this->assertSame('value1', $c->get('name1'));

		$newValues = ['name1' => 'new-value1', 'name2' => 'new-value2'];
		$c->setMultiple($newValues);
		$this->assertEquals($newValues, $c->all());
	}

	/**
	 * Ensure that the configured cache is actually used.
	 *
	 * @dataProvider dataCacheUsage
	 */
	public function testCacheUsage($platform, $config, $requiredExtension, $environment) {
		$this->initClient($requiredExtension, ['environment' => $environment . '_' . $platform, 'config' => $config]);

		$this->persistSetting(Setting::create('name', 'value'));

		$this->getService('craue_config')->all();

		$this->assertTrue($this->getService('craue_config_cache_adapter')->has('name'));
	}

	public function dataCacheUsage() {
		$testData = self::duplicateTestDataForEachPlatform([
			['cache_SymfonyCacheComponent_filesystem'],
		], 'config_cache_SymfonyCacheComponent_filesystem.yml');

		if (!empty($_ENV['REDIS_DSN'])) {
			$testData = array_merge($testData, self::duplicateTestDataForEachPlatform([
				['cache_SymfonyCacheComponent_redis'],
			], 'config_cache_SymfonyCacheComponent_redis.yml'));
		}

		// TODO remove as soon as Symfony >= 5.0 is required
		if (class_exists(\Doctrine\Bundle\DoctrineCacheBundle\DoctrineCacheBundle::class)) {
			$testData = array_merge($testData, self::duplicateTestDataForEachPlatform([
				['cache_DoctrineCacheBundle_file_system'],
			], 'config_cache_DoctrineCacheBundle_file_system.yml'));
		}

		return $testData;
	}

	/**
	 * Ensure that a custom config class can actually be used with a custom model class.
	 *
	 * @dataProvider dataCustomEntity
	 */
	public function testCustomEntity($platform, $config, $requiredExtension, $environment) {
		$this->initClient($requiredExtension, ['environment' => $environment . '_' . $platform, 'config' => $config]);
		$customSetting = $this->persistSetting(CustomSetting::create('name1', 'value1', 'section1', 'comment1'));

		$customConfig = $this->getService('craue_config');
		$this->assertInstanceOf(CustomConfig::class, $customConfig);

		$fetchedSetting = $customConfig->getRawSetting('name1');
		$this->assertSame($customSetting, $fetchedSetting);
		$this->assertEquals('value1', $customConfig->get('name1'));
	}

	public function dataCustomEntity() {
		return self::duplicateTestDataForEachPlatform([
			['customEntity'],
		], 'config_customEntity.yml');
	}

	/**
	 * Ensure that the database enforces a unique name for settings.
	 *
	 * @dataProvider getPlatformConfigs
	 */
	public function testDefaultEntityNameUnique($platform, $config, $requiredExtension) {
		$this->initClient($requiredExtension, ['environment' => $platform, 'config' => $config]);

		$this->assertSame(['name'], $this->getEntityManager()->getClassMetadata(Setting::class)->getIdentifier());

		$this->persistSetting(Setting::create('name1'));

		$this->expectExceptionForEntityNameUnique(Setting::class, $platform, 'craue_config_setting');
		$this->persistSetting(Setting::create('name1'));
	}

	/**
	 * Ensure that the database enforces a unique name for settings with a custom entity.
	 *
	 * @dataProvider dataCustomEntity
	 */
	public function testCustomEntityNameUnique($platform, $config, $requiredExtension, $environment) {
		$this->initClient($requiredExtension, ['environment' => $environment . '_' . $platform, 'config' => $config]);

		$this->assertSame(['name'], $this->getEntityManager()->getClassMetadata(CustomSetting::class)->getIdentifier());

		$this->persistSetting(CustomSetting::create('name1'));

		$this->expectExceptionForEntityNameUnique(CustomSetting::class, $platform, 'craue_config_setting_custom');
		$this->persistSetting(CustomSetting::create('name1'));
	}

	private function expectExceptionForEntityNameUnique(string $entityName, string $platform, string $tableName) : void {
		/**
		 * TODO clean up as soon as doctrine/orm >= 2.16.1 is required
		 *  - doctrine/orm < 2.16.0 throws a UniqueConstraintViolationException
		 *  - doctrine/orm 2.16.0 throws a RuntimeException instead of a UniqueConstraintViolationException, see https://github.com/doctrine/orm/pull/10785
		 *  - doctrine/orm 2.16.1 will (hopefully) throw a EntityIdentityCollisionException, see https://github.com/doctrine/orm/issues/10872
		 */

		$orm216message = sprintf('While adding an entity of class %1$s with an ID hash of "%2$s" to the identity map,%3$sanother object of class %1$s was already present for the same ID.', $entityName, 'name1', "\n");

		if (class_exists(\Doctrine\ORM\Exception\EntityIdentityCollisionException::class)) {
			// doctrine/orm > 2.16.0
			$this->expectException(\Doctrine\ORM\Exception\EntityIdentityCollisionException::class);
			$this->expectExceptionMessage($orm216message);
		} elseif (class_exists(\Doctrine\ORM\Internal\TopologicalSort::class)) {
			// doctrine/orm = 2.16.0 (TopologicalSort class was added in this version)
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage($orm216message);
		} else {
			// doctrine/orm < 2.16.0
			$this->expectException(UniqueConstraintViolationException::class);
				switch ($platform) {
					case self::PLATFORM_MYSQL:
						$this->expectExceptionMessageMatches(sprintf('/%s%s%s/s', preg_quote("Integrity constraint violation: 1062 Duplicate entry 'name1' for key '"), sprintf('(%s\.)?PRIMARY', $tableName), preg_quote("'")));
						break;
					case self::PLATFORM_SQLITE:
						$this->expectExceptionMessageMatches(sprintf('/%s/s', preg_quote(sprintf("UNIQUE constraint failed: %s.name", $tableName))));
						break;
				}
		}
	}

	/**
	 * Ensure that the database table is only created for the custom entity, but not for the bundle's original one.
	 *
	 * @dataProvider dataCustomEntity
	 */
	public function testCustomEntityTableCreation($platform, $config, $requiredExtension, $environment) {
		$this->initClient($requiredExtension, array('environment' => $environment . '_' . $platform, 'config' => $config));

		$em = $this->getEntityManager();
		$schemaTool = new SchemaTool($em);
		$schema = $schemaTool->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

		$this->assertTrue($schema->hasTable('craue_config_setting_custom'));
		$this->assertFalse($schema->hasTable('craue_config_setting'));
	}

}
