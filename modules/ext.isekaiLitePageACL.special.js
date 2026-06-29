( function () {
	'use strict';

	$( function () {
		$( '.ext-isekai-lpacl-user-input' ).each( function () {
			try {
				OO.ui.infuse( this );
			} catch ( e ) {
				// The plain HTML input remains usable when OOUI infusion is unavailable.
			}
		} );
	} );
}() );
