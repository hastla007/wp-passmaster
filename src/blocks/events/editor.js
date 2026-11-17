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
	// A501: a shortcode block (or pasted shortcode) [wpcap_events …] becomes this block, with all its
	// attributes (event, occurrence, dates, limit, layout).
	function id( value ) { var n = parseInt( value, 10 ); return n > 0 ? n : 0; }
	function layoutOf( value ) { value = String( value || '' ).toLowerCase(); return 'list' === value || 'cards' === value ? value : 'auto'; }
	var fromShortcode = {
		type: 'shortcode',
		tag: [ 'wpcap_events', 'wp_pass_captain_events' ],
		attributes: {
			event: { type: 'integer', shortcode: function ( a ) { return id( a.named.event ); } },
			occurrence: { type: 'integer', shortcode: function ( a ) { return id( a.named.occurrence ); } },
			perDate: { type: 'boolean', shortcode: function ( a ) { return 'each' === String( a.named.dates || '' ).toLowerCase(); } },
			limit: { type: 'integer', shortcode: function ( a ) { var n = id( a.named.limit ); return n ? Math.min( 200, n ) : 50; } },
			layout: { type: 'string', shortcode: function ( a ) { return layoutOf( a.named.layout ); } }
		}
	};
	blocks.registerBlockType( 'wp-pass-captain/events', {
		transforms: { from: [ fromShortcode ] },
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			// eventId/occurrenceId: attribute names of older blocks, read when event/occurrence are not set.
			var eventId = parseInt( attrs.event, 10 ) || parseInt( attrs.eventId, 10 ) || 0;
			var occurrenceId = parseInt( attrs.occurrence, 10 ) || parseInt( attrs.occurrenceId, 10 ) || 0;
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
					el( components.SelectControl, { label: __( 'Event', 'pass-captain' ), value: String( eventId ), options: eventOptions, onChange: function ( v ) { props.setAttributes( { event: parseInt( v, 10 ) || 0, occurrence: 0, eventId: 0, occurrenceId: 0 } ); } } ),
					eventId ? el( components.SelectControl, { label: __( 'Termin', 'pass-captain' ), value: String( occurrenceId ), options: occurrenceOptions, help: occurrenceHelp, onChange: function ( v ) { props.setAttributes( { event: eventId, occurrence: parseInt( v, 10 ) || 0, eventId: 0, occurrenceId: 0 } ); } } ) : null,
					// A342: by default one card per event with its other dates as a choice; optionally one card per date.
					! occurrenceId && components.ToggleControl ? el( components.ToggleControl, { label: __( 'Jeden Termin als eigene Karte', 'pass-captain' ), help: __( 'Aus: eine Karte je Event, die weiteren Termine sind darin auswählbar.', 'pass-captain' ), checked: !! attrs.perDate, onChange: function ( v ) { props.setAttributes( { perDate: !! v } ); } } ) : null
				),
				el( components.PanelBody, { title: __( 'Anzeige', 'pass-captain' ) },
					! occurrenceId ? el( components.SelectControl, {
						label: __( 'Darstellung', 'pass-captain' ),
						value: layoutOf( attrs.layout ),
						options: [
							{ label: __( 'Automatisch (Ticketkarten, bei sehr vielen Karten Terminliste)', 'pass-captain' ), value: 'auto' },
							{ label: __( 'Terminliste mit „Tickets“-Button', 'pass-captain' ), value: 'list' },
							{ label: __( 'Ticketauswahl für jeden Termin', 'pass-captain' ), value: 'cards' }
						],
						onChange: function ( v ) { props.setAttributes( { layout: layoutOf( v ) } ); }
					} ) : null,
					! occurrenceId && components.TextControl ? el( components.TextControl, {
						label: __( 'Höchstens so viele Termine', 'pass-captain' ),
						type: 'number',
						min: 1,
						max: 200,
						value: attrs.limit || 50,
						onChange: function ( v ) { var n = parseInt( v, 10 ) || 0; props.setAttributes( { limit: n > 0 ? Math.min( 200, n ) : 50 } ); }
					} ) : null
				)
			) : null;
			return el( element.Fragment, {}, controls, el( 'div', blockProps, preview( el( serverSideRender, { block: 'wp-pass-captain/events', attributes: attrs } ) ) ) );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
