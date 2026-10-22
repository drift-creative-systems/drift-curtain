/**
 * Settings → Drift: Curtain: media pickers and the preview-token generator.
 * Enqueued on that screen only.
 */
( function ( $ ) {
	'use strict';

	var strings = window.driftCurtainAdmin || {};

	$( document ).on( 'click.driftCurtain', '.drift-curtain-media-select', function ( e ) {
		e.preventDefault();
		var fieldName = $( this ).data( 'field' );
		var input     = $( '#' + fieldName );
		var preview   = $( '#' + fieldName + '_preview' );
		var removeBtn = $( '#' + fieldName + '_remove' );

		var frame = wp.media( {
			title: strings.mediaTitle || 'Select image',
			button: { text: strings.mediaButton || 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			input.val( attachment.url );
			preview.attr( 'src', attachment.url ).prop( 'hidden', false );
			removeBtn.prop( 'hidden', false );
		} );

		frame.open();
	} );

	$( document ).on( 'click.driftCurtain', '.drift-curtain-media-remove', function ( e ) {
		e.preventDefault();
		var fieldName = $( this ).data( 'field' );
		$( '#' + fieldName ).val( '' );
		$( '#' + fieldName + '_preview' ).prop( 'hidden', true ).attr( 'src', '' );
		$( this ).prop( 'hidden', true );
	} );

	// Preview token: 24 characters from the browser's CSPRNG. The token is
	// the only thing stopping strangers bypassing maintenance mode, so it
	// shouldn't come from Math.random().
	$( document ).on( 'click.driftCurtain', '#drift_curtain_generate_token', function ( e ) {
		e.preventDefault();
		var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		var bytes = new Uint32Array( 24 );
		var token = '';

		window.crypto.getRandomValues( bytes );
		for ( var i = 0; i < bytes.length; i++ ) {
			token += chars.charAt( bytes[ i ] % chars.length );
		}

		$( '#drift_curtain_preview_token' ).val( token );
	} );

	$( document ).on( 'focus.driftCurtain click.driftCurtain', '.drift-curtain-select-on-focus', function () {
		$( this ).trigger( 'select' );
	} );
} )( jQuery );
