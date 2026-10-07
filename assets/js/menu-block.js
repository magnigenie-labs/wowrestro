( function ( blocks, element, i18n, components, blockEditor ) {
	blocks.registerBlockType( 'wowrestro/menu', {
		edit: function ( props ) {
			const templates = Object.assign( { 0: i18n.__( 'Use WowRestro default', 'wowrestro' ) }, window.wowRestroMenuTemplates || {} );
			return element.createElement( element.Fragment, null,
				element.createElement( blockEditor.InspectorControls, null,
					element.createElement( components.PanelBody, { title: i18n.__( 'Menu display', 'wowrestro' ) },
						element.createElement( components.SelectControl, { label: i18n.__( 'Template', 'wowrestro' ), value: props.attributes.template, options: Object.keys( templates ).map( function ( key ) { return { label: templates[ key ], value: Number( key ) }; } ), onChange: function ( value ) { props.setAttributes( { template: Number( value ) } ); } } ),
						element.createElement( components.SelectControl, { label: i18n.__( 'Display', 'wowrestro' ), value: props.attributes.layout, options: [ { label: i18n.__( 'Use WowRestro default', 'wowrestro' ), value: '' }, { label: i18n.__( 'Category tabs', 'wowrestro' ), value: 'tabs' }, { label: i18n.__( 'List', 'wowrestro' ), value: 'list' } ], onChange: function ( value ) { props.setAttributes( { layout: value } ); } } ),
						element.createElement( components.TextControl, { label: i18n.__( 'Location slug', 'wowrestro' ), value: props.attributes.location, onChange: function ( value ) { props.setAttributes( { location: value } ); } } )
					)
				),
				element.createElement(
				'div',
				{ className: 'wowrestro-block-preview' },
				element.createElement( 'strong', null, i18n.__( 'WowRestro ordering menu', 'wowrestro' ) ),
				element.createElement( 'p', null, props.attributes.template ? i18n.sprintf( i18n.__( 'Template %1$d · %2$s', 'wowrestro' ), props.attributes.template, props.attributes.layout || i18n.__( 'WowRestro default display', 'wowrestro' ) ) : i18n.__( 'Uses the defaults from WowRestro → Menu Display', 'wowrestro' ) )
				)
			);
		},
		save: function () { return null; }
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.components, window.wp.blockEditor );
