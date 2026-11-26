<?php

namespace teamnovu\publisher;

use craft\base\Element;
use craft\base\Plugin;
use Craft;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use teamnovu\publisher\mcp\PublisherTools;
use teamnovu\publisher\services\Entries;
use yii\base\Event;

/**
 * @author    novu
 * @package   Publisher X
 * @since     0.1.0
 *
 * @property \teamnovu\publisher\services\Entries $entries
 *
 */
class Publisher extends Plugin
{
    /**
     * @inheritdoc
     */
    public string $schemaVersion = '2.0.6';

    public function init()
    {
        parent::init();

        $this->setComponents(
            [
                'entries' => Entries::class,
            ]
        );

        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'teamnovu\publisher\console\controllers';
        }

        // MCP requests are not always control panel requests: register outside the CP block.
        $this->registerMcpCapabilities();

        $isCpRequest = Craft::$app->getRequest()->getIsCpRequest();

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (ElementEvent $event) use ($isCpRequest) {
                if (!$event->element instanceof Entry || !$event->element->isCanonical) {
                    return;
                }

                // Saves from the control panel or from Craft's MCP server.
                if (!$isCpRequest && !self::isMcpRequest()) {
                    return;
                }

                $entries = self::getInstance()->entries;
                // The MCP stdio server is a long-running process: compare with the current time.
                $entries->setNow(new \DateTime('now', new \DateTimeZone(Craft::$app->getTimeZone())));
                $entries->onSaveEntry($event->element);
            }
        );

        if ($isCpRequest) {
            Event::on(
                CraftVariable::class,
                CraftVariable::EVENT_INIT,
                function (Event $event) {
                    $variable = $event->sender;
                    $variable->set('publisherEntries', Entries::class);
                }
            );

            Event::on(
                Element::class,
                Element::EVENT_DEFINE_SIDEBAR_HTML,
                function (DefineHtmlEvent $event) {
                    $element = $event->sender;

                    // On Craft 6 craft\elements\Entry is an alias, so get_class() returns the native class.
                    if ($element instanceof Entry) {
                        $isNew = $element->id === null;

                        if ($isNew) {
                            return;
                        }

                        if ($element->getIsDraft()) {
                            $element = $element->getCanonical();
                        }

                        if ($element->getSection()) {
                            $event->html .= Craft::$app->view->renderTemplate(
                                'publisher-x/_cp/entriesEditRightPane',
                                [
                                    'permissionSuffix' => ':' . $element->getSection()->uid,
                                    'entry'            => $element,
                                ]
                            );
                        }
                    }
                }
            );
        }
    }

    /**
     * Registers the Publisher X tools on Craft's MCP server (Craft 5.12+ and Craft 6). Does nothing on
     * earlier Craft versions.
     */
    private function registerMcpCapabilities(): void
    {
        if (!class_exists('Mcp\Capability\Attribute\McpTool')) {
            return;
        }

        if (class_exists('CraftCms\Cms\Mcp\CapabilityRegistry')) {
            app(\CraftCms\Cms\Mcp\CapabilityRegistry::class)->register(PublisherTools::class);

            return;
        }

        if (!class_exists('craft\services\Mcp')) {
            return;
        }

        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PublisherTools::class;
            }
        );
    }

    /**
     * Whether the current request is handled by Craft's MCP server, over HTTP or stdio.
     */
    private static function isMcpRequest(): bool
    {
        // Craft 6 has no Yii MCP controller; it tags MCP requests (HTTP and stdio) with an activity origin.
        if (class_exists('CraftCms\Cms\Activity\ActivityEventRecorder')) {
            return \Illuminate\Support\Facades\Context::getHidden(
                \CraftCms\Cms\Activity\ActivityEventRecorder::ContextOrigin
            ) === 'MCP';
        }

        $controller = Craft::$app->controller;

        return $controller !== null && in_array(get_class($controller), [
            'craft\controllers\McpController',
            'craft\console\controllers\McpController',
        ], true);
    }
}
