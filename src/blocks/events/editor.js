( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };
	var sprintf = i18n && i18n.sprintf ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( format ).replace( /%d/g, function () { return String( args.shift() ); } );
	};
	// Published events and their upcoming dates (labels with date, so same-named events are distinguishable).
	var data = window.WPCAP_BLOCK_EVENTS || { events: [] };
	// A451: the server-rendered preview contains the real order form; inside Disabled its fields,
	// buttons and links cannot be focused or used in the editor.
	function preview( child ) {
		return components && components.Disabled ? el( components.Disabled, {}, child ) : child;
	}
	blocks.registerBlockType( 'wp-pass-captain/events', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			var eventId = parseInt( attrs.event, 10 ) || 0;
			var occurrenceId = parseInt( attrs.occurrence, 10 ) || 0;
			var current = data.events.filter( function ( e ) { return e.id === eventId; } )[ 0 ];
			var eventOptions = [ { value: '0', label: __( 'Alle Events', 'pass-captain' ) } ];
			// A453: a saved event that is no longer listed (unpublished, no upcoming dates) stays
			// visible as such instead of the select silently showing "Alle Events".
			/* translators: %d: event ID */
			if ( eventId && ! current ) { eventOptions.push( { value: String( eventId ), label: sprintf( __( 'Event #%d (nicht mehr verfügbar)', 'pass-captain' ), eventId ) } ); }
			eventOptions = eventOptions.concat( data.events.map( function ( e ) { return { value: String( e.id ), label: e.label }; } ) );
			var occurrences = current ? current.occurrences : [];
			var occurrenceOptions = [ { value: '0', label: __( 'Alle Termine', 'pass-captain' ) } ];
			var listed = occurrences.some( function ( o ) { return o.id === occurrenceId; } );
			if ( occurrenceId && ! listed ) {
				occurrenceOptions.push( { value: String( occurrenceId ), label: current && current.more
					/* translators: %d: occurrence (date) ID */
					? sprintf( __( 'Termin #%d', 'pass-captain' ), occurrenceId )
					/* translators: %d: occurrence (date) ID */
					: sprintf( __( 'Termin #%d (nicht mehr verfügbar)', 'pass-captain' ), occurrenceId ) } );
			}
			occurrenceOptions = occurrenceOptions.concat( occurrences.map( function ( o ) { return { value: String( o.id ), label: o.label }; } ) );
			/* translators: 1: number of listed dates, 2: number of upcoming dates */
			var occurrenceHelp = current && current.more ? sprintf( __( 'Angezeigt werden die nächsten %1$d von %2$d Terminen.', 'pass-captain' ), occurrences.length, current.dates ) : undefined;
			var controls = blockEditor.InspectorControls && components && components.SelectControl ? el( blockEditor.InspectorControls, {},
				el( components.PanelBody, { title: __( 'Auswahl', 'pass-captain' ) },
					el( components.SelectControl, { label: __( 'Event', 'pass-captain' ), value: String( eventId ), options: eventOptions, onChange: function ( v ) { props.setAttributes( { event: parseInt( v, 10 ) || 0, occurrence: 0 } ); } } ),
					eventId ? el( components.SelectControl, { label: __( 'Termin', 'pass-captain' ), value: String( occurrenceId ), options: occurrenceOptions, help: occurrenceHelp, onChange: function ( v ) { props.setAttributes( { occurrence: parseInt( v, 10 ) || 0 } ); } } ) : null
				)
			) : null;
			return el( element.Fragment, {}, controls, el( 'div', blockProps, preview( el( serverSideRender, { block: 'wp-pass-captain/events', attributes: attrs } ) ) ) );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
