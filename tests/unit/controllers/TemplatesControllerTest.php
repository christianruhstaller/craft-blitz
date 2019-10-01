<?php
/**
 * @link      https://craftcampaign.com
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitztests\unit;

use Craft;
use craft\web\Response;
use craft\web\twig\TemplateLoaderException;
use yii\web\BadRequestHttpException;

/**
 * @author    PutYourLightsOn
 * @package   Blitz
 * @since     2.3.0
 */

class TemplatesControllerTest extends BaseControllerTest
{
    // Public methods
    // =========================================================================

    public function testGetBadRequestHttpException()
    {
        // Expect an exception
        $this->expectException(BadRequestHttpException::class);

        $this->runActionWithParams('templates/get', [
            'template' => '_nonexistant',
        ]);
    }

    public function testGetTemplateLoaderException()
    {
        // Expect an exception
        $this->expectException(TemplateLoaderException::class);

        $this->runActionWithParams('templates/get', [
            'template' => Craft::$app->getSecurity()->hashData('_nonexistant'),
        ]);
    }

    public function testGetSuccess()
    {
        Craft::$app->getView()->setTemplateMode('site');

        $response = $this->runActionWithParams('templates/get', [
            'template' => Craft::$app->getSecurity()->hashData('_hidden'),
        ]);

        $this->assertInstanceOf(Response::class, $response);

        // Assert that the output is correct
        $this->assertEquals('xyz', trim($response->data));
    }
}