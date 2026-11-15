( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	function number( props, key, label, help ) {
		return el( components.TextControl, {
			label: label,
			help: help,
			type: 'number',
			min: 0,
			value: props.attributes[ key ] || '',
			onChange: function ( value ) { var next = {}; next[ key ] = parseInt( value, 10 ) || 0; props.setAttributes( next ); }
		} );
	}
	blocks.registerBlockType( 'wp-pass-captain/events', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			var attrs = props.attributes;
			return el( 'div', blockProps,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, { title: __( 'Anzeige', 'pass-captain' ) },
						el( components.SelectControl, {
							label: __( 'Darstellung', 'pass-captain' ),
							value: attrs.layout,
							options: [
								{ label: __( 'Automatisch (bis 3 Termine mit Ticketauswahl, sonst Liste)', 'pass-captain' ), value: 'auto' },
								{ label: __( 'Terminliste mit „Tickets“-Button', 'pass-captain' ), value: 'list' },
								{ label: __( 'Ticketauswahl für jeden Termin', 'pass-captain' ), value: 'cards' }
							],
							onChange: function ( value ) { props.setAttributes( { layout: value } ); }
						} ),
						number( props, 'eventId', __( 'Nur Event-ID (optional)', 'pass-captain' ) ),
						number( props, 'occurrenceId', __( 'Nur Termin-ID (optional)', 'pass-captain' ), __( 'Zeigt direkt die Ticketauswahl dieses Termins.', 'pass-captain' ) ),
						number( props, 'limit', __( 'Höchstens so viele Termine', 'pass-captain' ) )
					)
				),
				el( serverSideRender, { block: 'wp-pass-captain/events', attributes: attrs } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
