<?php
declare(strict_types=1);

/**
 * Markdown Helper
 *
 * Simple regex-based Markdown parser for notices.
 * Supports: bold, italic, links, lists, line breaks.
 * Always sanitizes input to prevent XSS.
 */
class Markdown
{
    /**
     * Render markdown text to HTML
     *
     * @param string $text Raw markdown text
     * @return string Safe HTML output
     */
    public static function render(string $text): string
    {
        // First, sanitize all HTML to prevent XSS
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Process markdown syntax
        $text = self::parseHeaders($text);
        $text = self::parseBold($text);
        $text = self::parseItalic($text);
        $text = self::parseLinks($text);
        $text = self::parseUnorderedLists($text);
        $text = self::parseOrderedLists($text);
        $text = self::parseLineBreaks($text);
        $text = self::parseParagraphs($text);

        return $text;
    }

    /**
     * Parse **bold** text
     */
    private static function parseBold(string $text): string
    {
        // **bold** or __bold__
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);

        return $text;
    }

    /**
     * Parse *italic* text
     */
    private static function parseItalic(string $text): string
    {
        // *italic* or _italic_ (single asterisk/underscore)
        // Must not match ** or __ which are bold
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/s', '<em>$1</em>', $text);

        return $text;
    }

    /**
     * Parse [link text](url) format
     */
    private static function parseLinks(string $text): string
    {
        // [text](url) format - URL was already escaped by htmlspecialchars
        // We need to decode the URL for proper linking
        return preg_replace_callback(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            function ($matches) {
                $linkText = $matches[1];
                // Decode the URL that was escaped by htmlspecialchars
                $url = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');

                // Validate and sanitize URL
                $url = self::sanitizeUrl($url);
                if ($url === null) {
                    return $linkText; // Invalid URL, just return text
                }

                // Re-escape URL for HTML attribute
                $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

                return '<a href="' . $escapedUrl . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>';
            },
            $text
        );
    }

    /**
     * Sanitize a URL, returning null if invalid/unsafe
     */
    private static function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);

        // Only allow http, https, and mailto protocols
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        if (preg_match('/^mailto:/i', $url)) {
            return $url;
        }

        // Allow relative URLs starting with /
        if (str_starts_with($url, '/')) {
            return $url;
        }

        // Block javascript:, data:, and other potentially dangerous schemes
        if (preg_match('/^[a-z]+:/i', $url)) {
            return null;
        }

        // Assume https for URLs without protocol
        return 'https://' . $url;
    }

    /**
     * Parse unordered lists (lines starting with - or *)
     */
    private static function parseUnorderedLists(string $text): string
    {
        // Split into lines
        $lines = explode("\n", $text);
        $result = [];
        $inList = false;

        foreach ($lines as $line) {
            // Check for list item (- or * at start of line)
            if (preg_match('/^[\-\*]\s+(.+)$/', $line, $matches)) {
                if (!$inList) {
                    $result[] = '<ul>';
                    $inList = true;
                }
                $result[] = '<li>' . trim($matches[1]) . '</li>';
            } else {
                if ($inList) {
                    $result[] = '</ul>';
                    $inList = false;
                }
                $result[] = $line;
            }
        }

        if ($inList) {
            $result[] = '</ul>';
        }

        return implode("\n", $result);
    }

    /**
     * Parse ordered lists (lines starting with numbers)
     */
    private static function parseOrderedLists(string $text): string
    {
        $lines = explode("\n", $text);
        $result = [];
        $inList = false;

        foreach ($lines as $line) {
            // Check for ordered list item (1. or 1) at start of line)
            if (preg_match('/^\d+[\.\)]\s+(.+)$/', $line, $matches)) {
                if (!$inList) {
                    $result[] = '<ol>';
                    $inList = true;
                }
                $result[] = '<li>' . trim($matches[1]) . '</li>';
            } else {
                if ($inList) {
                    $result[] = '</ol>';
                    $inList = false;
                }
                $result[] = $line;
            }
        }

        if ($inList) {
            $result[] = '</ol>';
        }

        return implode("\n", $result);
    }

    /**
     * Parse headers (# for h1, ## for h2, etc.)
     */
    private static function parseHeaders(string $text): string
    {
        // Match lines starting with # (up to 6 levels)
        $text = preg_replace('/^######\s+(.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^#####\s+(.+)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $text);

        return $text;
    }

    /**
     * Convert line breaks to <br> tags
     * Only applies within paragraphs, not after block elements
     */
    private static function parseLineBreaks(string $text): string
    {
        // Replace double newlines with paragraph markers
        $text = str_replace("\r\n", "\n", $text);

        // Single newlines become <br> (except after block elements)
        $lines = explode("\n", $text);
        $result = [];

        foreach ($lines as $i => $line) {
            $result[] = $line;

            // If this line is not empty and next line exists and is not empty
            // and neither are block elements, add <br>
            if (
                $line !== '' &&
                isset($lines[$i + 1]) &&
                $lines[$i + 1] !== '' &&
                !self::isBlockElement($line) &&
                !self::isBlockElement($lines[$i + 1])
            ) {
                // Add <br> at end of current line
                $lastIdx = count($result) - 1;
                $result[$lastIdx] .= '<br>';
            }
        }

        return implode("\n", $result);
    }

    /**
     * Check if a line contains a block element
     */
    private static function isBlockElement(string $line): bool
    {
        $line = trim($line);

        // Check for common block elements
        return preg_match('/^<(ul|ol|li|h[1-6]|\/ul|\/ol)/', $line) === 1;
    }

    /**
     * Wrap text blocks in paragraph tags
     */
    private static function parseParagraphs(string $text): string
    {
        // Split by double newlines (paragraph breaks)
        $blocks = preg_split('/\n\s*\n/', $text);
        $result = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            // Don't wrap if it's already a block element
            if (preg_match('/^<(ul|ol|h[1-6]|p|div|blockquote)/', $block)) {
                $result[] = $block;
            } else {
                $result[] = '<p>' . $block . '</p>';
            }
        }

        return implode("\n", $result);
    }

    /**
     * Strip all markdown and return plain text
     *
     * @param string $text
     * @return string
     */
    public static function stripMarkdown(string $text): string
    {
        // Remove bold/italic markers
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = preg_replace('/__(.+?)__/s', '$1', $text);
        $text = preg_replace('/\*(.+?)\*/s', '$1', $text);
        $text = preg_replace('/_(.+?)_/s', '$1', $text);

        // Extract link text from [text](url)
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);

        // Remove header markers
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);

        // Remove list markers
        $text = preg_replace('/^[\-\*]\s+/m', '', $text);
        $text = preg_replace('/^\d+[\.\)]\s+/m', '', $text);

        return $text;
    }

    /**
     * Truncate markdown text to a specified length, preserving word boundaries
     *
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    public static function truncate(string $text, int $length = 150, string $suffix = '...'): string
    {
        // First strip markdown to get plain text
        $plainText = self::stripMarkdown($text);

        if (strlen($plainText) <= $length) {
            return $plainText;
        }

        // Find a word boundary near the length
        $truncated = substr($plainText, 0, $length);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $length * 0.7) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return $truncated . $suffix;
    }
}
