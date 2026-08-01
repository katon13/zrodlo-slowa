<?php
namespace App\Controllers;

use App\Services\ArticleSeoService;

final class SitemapController extends BaseController
{
    public function index(): string
    {
        $seo = new ArticleSeoService(
            $this->app->db,
            $this->app->config['languages'] ?? [],
            $this->app->config['sites'] ?? []
        );

        $items = $seo->sitemapItems();
        header('Content-Type: application/xml; charset=UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($items as $item) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . $this->xml((string)$item['loc']) . "</loc>\n";
            if (!empty($item['lastmod'])) {
                $xml .= '    <lastmod>' . $this->xml((string)$item['lastmod']) . "</lastmod>\n";
            }
            foreach (($item['hreflang'] ?? []) as $language => $href) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="' . $this->xml((string)$language) . '" href="' . $this->xml((string)$href) . '" />' . "\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";

        return $xml;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
