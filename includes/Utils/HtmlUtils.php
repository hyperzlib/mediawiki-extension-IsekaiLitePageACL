<?php
namespace Isekai\LitePageACL\Utils;

use OOUI\FieldLayout;
use OOUI\HtmlSnippet;
use OOUI\Widget;

class HtmlUtils {
    public static function wrapByFieldLayout( $html, $fieldLayoutOpts = [ 'align' => 'top' ] ) {
        if ( is_string( $html ) ) {
            $html = new HtmlSnippet( $html );
        }
        return new FieldLayout( new Widget( [
            'content' => $html,
        ] ), $fieldLayoutOpts );
    }
}