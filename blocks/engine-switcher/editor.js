/**
 * Editor script for the `rmaps/engine-switcher` block.
 *
 * Plain (build-free) block registration — no JSX, no webpack. Uses the
 * `wp.*` globals WordPress already ships to the editor (`wp-blocks`,
 * `wp-block-editor`, `wp-element`, `wp-components`, `wp-i18n`), declared
 * as script deps in functions.php so they load first.
 *
 * The block is DYNAMIC: `save()` only persists the InnerBlocks content
 * (the rich markup the editor adds ABOVE the buttons). The actual
 * engine buttons are rendered server-side by the PHP `render_callback`
 * (see functions.php → template-parts/engine-switcher.php), so the
 * live button states / URLs / availability notice stay in sync with
 * the plugin without the editor needing any of that logic.
 *
 * In the editor we show:
 *   1. An InnerBlocks region (the editable content area), and
 *   2. A static, non-interactive mock of the three engine pills below
 *      it so the author can see where the buttons will land relative
 *      to their content.
 */
( function ( blocks, blockEditor, element, components, i18n ) {
	'use strict';

	var el                 = element.createElement;
	var Fragment           = element.Fragment;
	var __                 = i18n.__;
	var useBlockProps      = blockEditor.useBlockProps;
	var InnerBlocks        = blockEditor.InnerBlocks;
	var InspectorControls  = blockEditor.InspectorControls;
	var PanelBody          = components.PanelBody;
	var ToggleControl      = components.ToggleControl;
	var RangeControl       = components.RangeControl;

	// Static engine pill mock for the editor preview. Mirrors the
	// front-end markup class names so the bundled editor.css can paint
	// them with the same pill look.
	function enginePillMock( compact ) {
		var engines = [
			{ slug: 'google',   label: 'Google Maps' },
			{ slug: 'mapbox',   label: 'Mapbox' },
			{ slug: 'maplibre', label: 'MapLibre' }
		];
		return el(
			'div',
			{ className: 'rmaps-esb-mock', 'aria-hidden': 'true' },
			engines.map( function ( eng, i ) {
				return el(
					'span',
					{
						key: eng.slug,
						className:
							'rmaps-esb-mock__pill' +
							( i === 0 ? ' is-active' : '' )
					},
					el( 'span', { className: 'rmaps-esb-mock__dot rmaps-esb-mock__dot--' + eng.slug } ),
					compact ? null : el( 'span', {}, eng.label )
				);
			} )
		);
	}

	blocks.registerBlockType( 'rmaps/engine-switcher', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'rmaps-esb-editor' } );

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Engine switcher', 'rmaps-theme' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Compact (icons only)', 'rmaps-theme' ),
							help: __( 'Hide the engine names, keeping just the icons.', 'rmaps-theme' ),
							checked: !! attributes.compact,
							onChange: function ( value ) {
								setAttributes( { compact: !! value } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Content max width (px)', 'rmaps-theme' ),
							help: __( 'Caps the width of the content above the buttons. 0 = full width. The divider and buttons always span the full block width.', 'rmaps-theme' ),
							value: attributes.contentMaxWidth || 0,
							onChange: function ( value ) {
								setAttributes( { contentMaxWidth: value ? parseInt( value, 10 ) : 0 } );
							},
							min: 0,
							max: 1180,
							step: 10,
							allowReset: true
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'div',
						{
							className: 'rmaps-esb-editor__content',
							style: attributes.contentMaxWidth
								? { maxWidth: attributes.contentMaxWidth + 'px', marginInline: 'auto', width: '100%' }
								: undefined
						},
						el( InnerBlocks, {
							templateLock: false,
							placeholder: __(
								'Add content to show above the engine buttons (heading, text, image…)',
								'rmaps-theme'
							)
						} )
					),
					el(
						'div',
						{ className: 'rmaps-esb-editor__buttons' },
						enginePillMock( !! attributes.compact ),
						el(
							'p',
							{ className: 'rmaps-esb-editor__hint' },
							__( 'Engine buttons render here on the front end.', 'rmaps-theme' )
						)
					)
				)
			);
		},

		// Dynamic block — persist only the inner content; PHP renders
		// the wrapper + buttons.
		save: function () {
			return el( InnerBlocks.Content );
		}
	} );
} )(
	window.wp.blocks,
	window.wp.blockEditor,
	window.wp.element,
	window.wp.components,
	window.wp.i18n
);
