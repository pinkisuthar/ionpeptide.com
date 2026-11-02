import {
	useBlockProps,
} from '@wordpress/block-editor';
import { CheckboxControl } from '@woocommerce/blocks-checkout';
import { getSetting } from '@woocommerce/settings';
import './styles.css';

const { newsletter } = getSetting( 'omnisend_consent_data', '' );

export const Edit = () => {
	const blockProps = useBlockProps();

	if(!newsletter.optInEnabled) {
		return <div></div>;
	}

	return (
		<div { ...blockProps } id="omnisend-subscribe-block">
			<CheckboxControl style={{ marginTop: 0, lineHeight: 'normal' }}  id="newsletter-text" checked={ newsletter.optInPreselected } disabled={ true }>
				{newsletter.optInText}
			</CheckboxControl>
		</div>
	);
};
