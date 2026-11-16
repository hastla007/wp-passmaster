( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	var choices = ( window.WPCAP_BLOCK_SERVICE && window.WPCAP_BLOCK_SERVICE.events ) || [];
	blocks.registerBlockType( 'pass-captain/service-booking', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps();
			var attrs = props.attributes;
			var event = choices.filter( function ( e ) { return e.id === attrs.eventId; } )[ 0 ];
			var eventOptions = [ { label: __( '– Event wählen –', 'pass-captain' ), value: 0 } ].concat( choices.map( function ( e ) { return { label: e.label, value: e.id }; } ) );
			var resourceOptions = [ { label: __( 'Alle Angebote des Events', 'pass-captain' ), value: 0 } ].concat( ( event ? event.resources : [] ).map( function ( r ) { return { label: r.label, value: r.id }; } ) );
			return el( 'div', blockProps,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, { title: __( 'Terminbuchung', 'pass-captain' ) },
						choices.length ? el( components.SelectControl, {
							label: __( 'Event', 'pass-captain' ),
							value: attrs.eventId,
							options: eventOptions,
							onChange: function ( value ) { props.setAttributes( { eventId: parseInt( value, 10 ) || 0, resourceId: 0 } ); }
						} ) : el( components.TextControl, {
							label: __( 'Event-ID', 'pass-captain' ),
							type: 'number',
							value: attrs.eventId || '',
							onChange: function ( value ) { props.setAttributes( { eventId: parseInt( value, 10 ) || 0 } ); }
						} ),
						el( components.SelectControl, {
							label: __( 'Angebot (Ressource)', 'pass-captain' ),
							value: attrs.resourceId,
							options: resourceOptions,
							disabled: ! event,
							onChange: function ( value ) { props.setAttributes( { resourceId: parseInt( value, 10 ) || 0 } ); }
						} )
					)
				),
				attrs.eventId
					? el( serverSideRender, { block: 'pass-captain/service-booking', attributes: attrs } )
					: el( components.Placeholder, { icon: 'clock', label: __( 'Pass Captain Terminbuchung', 'pass-captain' ), instructions: __( 'Wähle in der Seitenleiste das Event mit den Service-Zeitfenstern.', 'pass-captain' ) } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
