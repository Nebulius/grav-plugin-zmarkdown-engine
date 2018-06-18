<?php
namespace Grav\Plugin;

use \DOMDocument;

use Grav\Common\Plugin;
use Grav\Common\Helpers\Excerpts;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class ZMarkdownEnginePlugin
 * @package Grav\Plugin
 */
class ZMarkdownEnginePlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) return;

        $this->enable([
            'onPageContentProcessed' => ['onPageContentProcessed', 0]
        ]);
    }

    /**
     * When the page is processed, if Markdown rendering is disabled,
     * renders with ZMD.
     *
     * @param Event $e
     */
    public function onPageContentProcessed(Event $e)
    {
        /** @var Page $page */
        $page = $e['page'];
        $config = $this->mergeConfig($page);

        $this->active = $config->get('active', true);

        // If the plugin is not active (either global or on page), exit.
        if (!$this->active) return;

        // Updates the content with the rendered ZMD.
        $page->setRawContent($this->renderZMarkdown($page));
    }

    private function renderZMarkdown($page)
    {
        require_once(__DIR__ . '/libs/simple_html_dom.php');

        $content = $page->getRawContent();

        // First, we ask nicely the ZMD server to parse the markdown string.

        $zmd_server = $this->grav['config']->get('plugins.zmarkdown-engine.zmd_server');
        $zmd_request = ['md' => $content];

        $zmd_request_str = json_encode($zmd_request);

        $ch = curl_init($zmd_server . '/html');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $zmd_request_str);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($zmd_request_str)
        ]);

        $result = curl_exec($ch);

        if (!$result && !empty($content))
        {
            return '<div class="custom-block custom-block-error"><div class="custom-block-content"><p><strong>Unable to parse Markdown.</strong><br />Please check that the zmarkdown server is reachable and does work.</p></div></div>' . "\n\n" . $content;
        }

        $html = json_decode($result)[0];

        // Then, we have some post-processing to do.
        // Grav allows to pass options to process images, and links, in Markdown. But these are processed using
        // Parsedown, and we removed it entierely. So we parse the generated HTML to find all images and links
        // to process them manually.

        // Arguments: html, lowercase, forceTagsClosed, charset (default = UTF-8), ignore line breaks.
        // We want to switch the last one as it breaks the code blocks.
        $html_tree = str_get_html($html, true, true, DEFAULT_TARGET_CHARSET, false);

        // The DOMDocument does not likes the HTML5 or MathML tags. This silents
        // its errors. We don't use it directly, but Excerpts::getExcerptFromHtml do.
        libxml_use_internal_errors(true);

        foreach ($html_tree->find('img') as $element)
        {
            $element->outertext = Excerpts::processImageHtml($element->outertext, $page);
        }

        // We would have to process links too, but Grav's getExcerptFromHtml actually does not
        // support tags with content (content not saved, so the re-constructed tag is always
        // empty). We could fix that but in the meantime, links are not processed.
        /*
        foreach ($html_tree->find('a[!aria-hidden]') as $element)
        {
            // Skips footnotes
            if (strpos($element->class, 'footnote-ref') !== false) continue;

            $element_html = $element->outertext;

            $excerpt = Excerpts::getExcerptFromHtml($element_html, 'a');
            $excerpt = Excerpts::processLinkExcerpt($excerpt, $page, 'link');

            $element->outertext = Excerpts::getHtmlFromExcerpt($excerpt);
        }
        */

        $html = $html_tree->save();
        $html_tree->clear();

        return $html;
    }
}