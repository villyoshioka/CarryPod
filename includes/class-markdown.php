<?php
/**
 * HTML to Markdown conversion for the Markdown versions of pages
 *
 * Text only: images, video, audio and embeds are removed, and links to media lose their URL,
 * so the Markdown never reveals where media files are.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'League\\HTMLToMarkdown\\';
        if ( ! str_starts_with( $class, $prefix ) ) {
            return;
        }

        $file = CP_PLUGIN_DIR . 'lib/html-to-markdown/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( is_file( $file ) ) {
            require $file;
        }
    }
);

class CP_Markdown {

    /** Extensions of image, video and audio files */
    private const string MEDIA_EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|heic|heif|jxl|mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg|oga|flac|aac|opus';

    /** Elements removed along with their content */
    private const string REMOVED_ELEMENTS = 'script style noscript template iframe frame object embed video audio source track svg canvas map form';

    /** @var string[] URL paths of media folders (e.g. /wp-content/uploads/) */
    private static array $media_paths = array();

    /**
     * Convert HTML to Markdown without any media
     *
     * @param string   $html        HTML fragment (post content)
     * @param string[] $media_paths URL paths of media folders; links and lines pointing there are removed
     */
    public static function convert( string $html, array $media_paths ): string {
        self::$media_paths = array_values( array_filter( $media_paths ) );

        $converter = new League\HTMLToMarkdown\HtmlConverter(
            array(
                'header_style' => 'atx',
                'strip_tags'   => true,
                'remove_nodes' => self::REMOVED_ELEMENTS,
            )
        );
        $environment = $converter->getEnvironment();
        $environment->addConverter( new League\HTMLToMarkdown\Converter\TableConverter() );
        $environment->addConverter( new CP_Markdown_Image_Converter() );
        $environment->addConverter( new CP_Markdown_Link_Converter() );

        $markdown = $converter->convert( $html );

        // Last line of defense (raw HTML left for tables, background images in style attributes, etc.).
        // ponytail: drops the whole line, so a code sample that mentions an uploads URL is lost too
        $lines = array_filter(
            explode( "\n", $markdown ),
            fn( string $line ): bool => ! self::contains_media_url( $line )
        );

        return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
    }

    /**
     * Whether the URL points to an image, video or audio file
     */
    public static function is_media_file( string $url ): bool {
        return 1 === preg_match( '#\.(?:' . self::MEDIA_EXTENSIONS . ')(?:[?\#]|$)#i', $url );
    }

    /**
     * Whether the text contains a URL of a media folder or of an image, video or audio file
     */
    public static function contains_media_url( string $text ): bool {
        foreach ( self::$media_paths as $path ) {
            if ( stripos( $text, $path ) !== false ) {
                return true;
            }
        }

        $ext = '\.(?:' . self::MEDIA_EXTENSIONS . ')(?![a-z0-9])';

        return 1 === preg_match(
            '#(?:https?:)?//[^\s<>()"\']+' . $ext
            . '|\]\(\s*<?[^)\s]*' . $ext
            . '|\b(?:src|srcset|href|poster|data-[a-z-]+)\s*=\s*["\']?[^"\'\s>]*' . $ext
            . '|url\(\s*["\']?[^)]*' . $ext . '#i',
            $text
        );
    }
}

/**
 * Replaces each image with its alt text, never its URL
 */
class CP_Markdown_Image_Converter implements League\HTMLToMarkdown\Converter\ConverterInterface {

    public function convert( League\HTMLToMarkdown\ElementInterface $element ): string {
        $alt = trim( (string) preg_replace( '/\s+/u', ' ', $element->getAttribute( 'alt' ) ) );

        return $alt === '' ? '' : '（画像: ' . League\HTMLToMarkdown\LinkSyntax::escapeText( $alt ) . '）';
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array {
        return array( 'img' );
    }
}

/**
 * Keeps only the text of links to media (lightboxes, attachment files)
 */
class CP_Markdown_Link_Converter extends League\HTMLToMarkdown\Converter\LinkConverter {

    public function convert( League\HTMLToMarkdown\ElementInterface $element ): string {
        $href = $element->getAttribute( 'href' );
        if ( CP_Markdown::contains_media_url( $href ) || CP_Markdown::is_media_file( $href ) ) {
            return trim( $element->getValue(), "\t\n\r\0\x0B" );
        }

        return parent::convert( $element );
    }
}
