<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Api;

use Magento\Authorization\Model\Role;
use Magento\Integration\Model\AdminTokenService;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\WebapiAbstract;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Authorization\Model\Rules;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\RoleFactory;

class CategoryRepositoryTest extends WebapiAbstract
{
    const RESOURCE_PATH = '/V1/categories';
    const SERVICE_NAME = 'catalogCategoryRepositoryV1';

    private $modelId = 333;

    /**
     * @var RulesFactory
     */
    private $rulesFactory;

    /**
     * @var RoleFactory
     */
    private $roleFactory;

    /**
     * @var AdminTokenService
     */
    private $adminTokenService;

    /**
     * Sets up common objects.
     *
     * @inheritDoc
     */
    protected function setUp()
    {
        $this->rulesFactory = Bootstrap::getObjectManager()->get(RulesFactory::class);
        $this->roleFactory = Bootstrap::getObjectManager()->get(RoleFactory::class);
        $this->adminTokenService = Bootstrap::getObjectManager()->get(AdminTokenService::class);
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/category_backend.php
     */
    public function testGet()
    {
        $expected = [
            'parent_id' => 2,
            'path' => '1/2/333',
            'position' => 1,
            'level' => 2,
            'available_sort_by' => ['position', 'name'],
            'include_in_menu' => true,
            'name' => 'Category 1',
            'id' => 333,
            'is_active' => true,
        ];

        $result = $this->getInfoCategory($this->modelId);

        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);
        $this->assertArrayHasKey('children', $result);
        $this->assertArrayHasKey('custom_attributes', $result);
        unset($result['created_at'], $result['updated_at'], $result['children'], $result['custom_attributes']);
        ksort($expected);
        ksort($result);
        $this->assertEquals($expected, $result);
    }

    public function testInfoNoSuchEntityException()
    {
        try {
            $this->getInfoCategory(-1);
        } catch (\Exception $e) {
            $this->assertContains('No such entity with %fieldName = %fieldValue', $e->getMessage());
        }
    }

    /**
     * @param int $id
     * @return string
     */
    protected function getInfoCategory($id)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => 'V1',
                'operation' => self::SERVICE_NAME . 'Get',
            ],
        ];
        return $this->_webApiCall($serviceInfo, ['categoryId' => $id]);
    }

    /**
     * Test for create category process
     *
     * @magentoApiDataFixture Magento/Catalog/Model/Category/_files/service_category_create.php
     */
    public function testCreate()
    {
        $categoryData = $this->getSimpleCategoryData(['name' => 'Test Category Name']);
        $result = $this->createCategory($categoryData);
        $this->assertGreaterThan(0, $result['id']);
        foreach (['name', 'parent_id', 'available_sort_by'] as $fieldName) {
            $this->assertEquals(
                $categoryData[$fieldName],
                $result[$fieldName],
                sprintf('"%s" field value is invalid', $fieldName)
            );
        }
        // delete category to clean up auto-generated url rewrites
        $this->deleteCategory($result['id']);
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/category.php
     */
    public function testDelete()
    {
        /** @var \Magento\UrlRewrite\Model\Storage\DbStorage $storage */
        $storage = Bootstrap::getObjectManager()->get(\Magento\UrlRewrite\Model\Storage\DbStorage::class);
        $categoryId = $this->modelId;
        $data = [
            UrlRewrite::ENTITY_ID => $categoryId,
            UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE
        ];
        /** @var \Magento\UrlRewrite\Service\V1\Data\UrlRewrite $urlRewrite*/
        $urlRewrite = $storage->findOneByData($data);

        // Assert that a url rewrite is auto-generated for the category created from the data fixture
        $this->assertEquals(1, $urlRewrite->getIsAutogenerated());
        $this->assertEquals($categoryId, $urlRewrite->getEntityId());
        $this->assertEquals(CategoryUrlRewriteGenerator::ENTITY_TYPE, $urlRewrite->getEntityType());
        $this->assertEquals('category-1.html', $urlRewrite->getRequestPath());

        // Assert deleting category is successful
        $this->assertTrue($this->deleteCategory($this->modelId));
        // After the category is deleted, assert that the associated url rewrite is also auto-deleted
        $this->assertNull($storage->findOneByData($data));
    }

    public function testDeleteNoSuchEntityException()
    {
        try {
            $this->deleteCategory(-1);
        } catch (\Exception $e) {
            $this->assertContains('No such entity with %fieldName = %fieldValue', $e->getMessage());
        }
    }

    /**
     * @dataProvider deleteSystemOrRootDataProvider
     * @expectedException \Exception
     */
    public function testDeleteSystemOrRoot()
    {
        $this->deleteCategory($this->modelId);
    }

    public function deleteSystemOrRootDataProvider()
    {
        return [
            [\Magento\Catalog\Model\Category::TREE_ROOT_ID],
            [2] //Default root category
        ];
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/category.php
     */
    public function testUpdate()
    {
        $categoryId = 333;
        $categoryData = [
            'name' => 'Update Category Test',
            'is_active' => false,
            'custom_attributes' => [
                [
                    'attribute_code' => 'description',
                    'value' => "Update Category Description Test",
                ],
            ],
        ];
        $result = $this->updateCategory($categoryId, $categoryData);
        $this->assertEquals($categoryId, $result['id']);
        /** @var \Magento\Catalog\Model\Category $model */
        $model = Bootstrap::getObjectManager()->get(\Magento\Catalog\Model\Category::class);
        $category = $model->load($categoryId);
        $this->assertFalse((bool)$category->getIsActive(), 'Category "is_active" must equal to false');
        $this->assertEquals("Update Category Test", $category->getName());
        $this->assertEquals("Update Category Description Test", $category->getDescription());
        // delete category to clean up auto-generated url rewrites
        $this->deleteCategory($categoryId);
    }

    protected function getSimpleCategoryData($categoryData = [])
    {
        return [
            'parent_id' => '2',
            'name' => isset($categoryData['name'])
                ? $categoryData['name'] : uniqid('Category-', true),
            'is_active' => '1',
            'include_in_menu' => '1',
            'available_sort_by' => ['position', 'name'],
            'custom_attributes' => [
                ['attribute_code' => 'url_key', 'value' => ''],
                ['attribute_code' => 'description', 'value' => 'Custom description'],
                ['attribute_code' => 'meta_title', 'value' => ''],
                ['attribute_code' => 'meta_keywords', 'value' => ''],
                ['attribute_code' => 'meta_description', 'value' => ''],
                ['attribute_code' => 'display_mode', 'value' => 'PRODUCTS'],
                ['attribute_code' => 'landing_page', 'value' => '0'],
                ['attribute_code' => 'is_anchor', 'value' => '0'],
                ['attribute_code' => 'custom_use_parent_settings', 'value' => '0'],
                ['attribute_code' => 'custom_apply_to_products', 'value' => '0'],
                ['attribute_code' => 'custom_design', 'value' => ''],
                ['attribute_code' => 'custom_design_from', 'value' => ''],
                ['attribute_code' => 'custom_design_to', 'value' => ''],
                ['attribute_code' => 'page_layout', 'value' => ''],
            ]
        ];
    }

    /**
     * Create category process
     *
     * @param  $category
     * @return int
     */
    protected function createCategory($category)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_POST
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => 'V1',
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];
        $requestData = ['category' => $category];
        return $this->_webApiCall($serviceInfo, $requestData);
    }

    /**
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    protected function deleteCategory($id)
    {
        $serviceInfo =
            [
                'rest' => [
                    'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                    'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_DELETE,
                ],
                'soap' => [
                    'service' => self::SERVICE_NAME,
                    'serviceVersion' => 'V1',
                    'operation' => self::SERVICE_NAME . 'DeleteByIdentifier',
                ],
            ];
        return $this->_webApiCall($serviceInfo, ['categoryId' => $id]);
    }

    /**
     * Make category update request.
     *
     * @param string $id
     * @param array $data
     * @param string|null $token
     * @return array|bool|float|int|string
     */
    function updateCategory($id, $data, ?string $token = null)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $id,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_PUT,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => 'V1',
                'operation' => self::SERVICE_NAME . 'Save',
            ],
        ];
        if ($token) {
            $serviceInfo['rest']['token'] = $token;
            $serviceInfo['soap']['token'] = $token;
        }

        $data['id'] = $id;
        return $this->_webApiCall($serviceInfo, ['id' => $id, 'category' => $data]);
    }

    /**
     * Test authorization when saving category's design settings.
     *
     * @magentoApiDataFixture Magento/Catalog/_files/category.php
     * @magentoApiDataFixture Magento/User/_files/user_with_new_role.php
     */
    public function testSaveDesign()
    {
        /** @var array $category */
        $category = $this->getInfoCategory(333);
        /** @var Role $role */
        $role = $this->roleFactory->create();
        $role->load('new_role', 'role_name');
        $token = $this->adminTokenService->createAdminAccessToken('admin_with_role', '12345abc');

        //Admin doesn't have access to category's design.
        /** @var Rules $rules */
        $rules = $this->rulesFactory->create();
        $rules->setRoleId($role->getId());
        $rules->setResources(['Magento_Catalog::categories']);
        $rules->saveRel();

        $category['custom_attributes'] = [['attribute_code' => 'custom_design', 'value' => 2]];
        $category = $this->updateCategory($category['id'], $category, $token);
        foreach ($category['custom_attributes'] as $attribute) {
            if ($attribute['attribute_code'] === 'custom_design') {
                if ($attribute['value']) {
                    $this->fail('Design attribute changed without proper access rights');
                }
            }
        }

        //Admin has access to category' design.
        /** @var Rules $rules */
        $rules = $this->rulesFactory->create();
        $rules->setRoleId($role->getId());
        $rules->setResources(['Magento_Catalog::categories', 'Magento_Catalog::edit_category_design']);
        $rules->saveRel();

        $changed = false;
        $category['custom_attributes'] = [['attribute_code' => 'custom_design', 'value' => 2]];
        $category = $this->updateCategory($category['id'], $category, $token);
        foreach ($category['custom_attributes'] as $attribute) {
            if ($attribute['attribute_code'] === 'custom_design') {
                if ($attribute['value'] == 2) {
                    $changed = true;
                }
            }
        }
        if (!$changed) {
            $this->fail('Failed to change a design attribute with proper access rights');
        }
    }
}
