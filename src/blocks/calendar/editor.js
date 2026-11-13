( function ( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	'use strict';
	var el = element.createElement;
	var __ = i18n.__;
	blocks.registerBlockType( 'wp-pass-captain/calendar', {
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps ? blockEditor.useBlockProps() : {};
			var attrs = props.attributes;
			return el( 'div', blockProps,
				el( blockEditor.InspectorControls, null,
					el( components.PanelBody, { title: __( 'Ansicht', 'pass-captain' ) },
						el( components.SelectControl, {
							label: __( 'Darstellung', 'pass-captain' ),
							value: attrs.view,
							options: [
								{ label: __( 'Monat', 'pass-captain' ), value: 'month' },
								{ label: __( 'Liste', 'pass-captain' ), value: 'list' }
							],
							onChange: function ( value ) { props.setAttributes( { view: value } ); }
						} ),
						el( components.TextControl, {
							label: __( 'Nur Event-ID (optional)', 'pass-captain' ),
							type: 'number',
							value: attrs.eventId || '',
							onChange: function ( value ) { props.setAttributes( { eventId: parseInt( value, 10 ) || 0 } ); }
						} )
					)
				),
				el( serverSideRender, { block: 'wp-pass-captain/calendar', attributes: attrs } )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.serverSideRender, window.wp.blockEditor, window.wp.components, window.wp.i18n );
