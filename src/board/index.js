/**
 * Editor registration for the Signalboard board block.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';
import './style.scss';
import './editor.scss';

// Signal-bars mark: matches the mark used for the admin menu icon, in its
// two-tone form (the accent bar picks out "the signal" among the four).
const icon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<rect x="1" y="10" width="4" height="10" rx="1.5" fill="#1A1A2E" />
		<rect x="7" y="2" width="4" height="18" rx="1.5" fill="#B5A642" />
		<rect x="13" y="14" width="4" height="6" rx="1.5" fill="#1A1A2E" />
		<rect x="19" y="6" width="4" height="14" rx="1.5" fill="#1A1A2E" />
	</svg>
);

registerBlockType( metadata.name, {
	icon,
	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const { board, defaultSort, allowSubmissions } = attributes;

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Board settings', 'signalboard' ) }>
						<TextControl
							label={ __( 'Board slug', 'signalboard' ) }
							help={ __(
								'Leave blank to show requests from all boards.',
								'signalboard'
							) }
							value={ board }
							onChange={ ( value ) =>
								setAttributes( { board: value } )
							}
						/>
						<SelectControl
							label={ __( 'Default sort', 'signalboard' ) }
							value={ defaultSort }
							options={ [
								{
									label: __( 'Newest', 'signalboard' ),
									value: 'date',
								},
								{
									label: __( 'Most votes', 'signalboard' ),
									value: 'votes',
								},
								{
									label: __( 'Title', 'signalboard' ),
									value: 'title',
								},
							] }
							onChange={ ( value ) =>
								setAttributes( { defaultSort: value } )
							}
						/>
						<ToggleControl
							label={ __( 'Allow submissions', 'signalboard' ) }
							help={ __(
								'Show a submission form on the board. Visitors must log in to submit; new requests enter a pending state for moderation.',
								'signalboard'
							) }
							checked={ !! allowSubmissions }
							onChange={ ( value ) =>
								setAttributes( { allowSubmissions: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		);
	},
	save() {
		return null;
	},
} );
