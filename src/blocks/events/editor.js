( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };
	// Published events and their upcoming dates (labels with date, so same-named events are distinguishable).
	var data = window.WPCAP_BLOCK_EVENTS || { events: [] };
	blocks.registerBlockType( 'wp-pass-captain/events', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			var eventOptions = [ { value: '0', label: __( 'Alle Events', 'wp-pass-captain' ) } ].concat( data.events.map( function ( e ) { return { value: String( e.id ), label: e.label }; } ) );
			var current = data.events.filter( function ( e ) { return e.id === attrs.event; } )[ 0 ];
			var occurrenceOptions = [ { value: '0', label: __( 'Alle Termine', 'wp-pass-captain' ) } ].concat( ( current ? current.occurrences : [] ).map( function ( o ) { return { value: String( o.id ), label: o.label }; } ) );
			var controls = blockEditor.InspectorControls && components && components.SelectControl ? el( blockEditor.InspectorControls, {},
				el( components.PanelBody, { title: __( 'Auswahl', 'wp-pass-captain' ) },
					el( components.SelectControl, { label: __( 'Event', 'wp-pass-captain' ), value: String( attrs.event || 0 ), options: eventOptions, onChange: function ( v ) { props.setAttributes( { event: parseInt( v, 10 ) || 0, occurrence: 0 } ); } } ),
					attrs.event ? el( components.SelectControl, { label: __( 'Termin', 'wp-pass-captain' ), value: String( attrs.occurrence || 0 ), options: occurrenceOptions, onChange: function ( v ) { props.setAttributes( { occurrence: parseInt( v, 10 ) || 0 } ); } } ) : null
				)
			) : null;
			return el( element.Fragment, {}, controls, el( 'div', blockProps, el( serverSideRender, { block: 'wp-pass-captain/events', attributes: attrs } ) ) );
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
