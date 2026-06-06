(function() {
	'use strict';

	var blocksRegistry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	var wpElement = window.wp && window.wp.element;
	var htmlEntities = window.wp && window.wp.htmlEntities;

	if (!blocksRegistry || !wcSettings || !wpElement || !htmlEntities) {
		return;
	}

	var settings = wcSettings.getSetting('corepay_money_data', {});
	var title = htmlEntities.decodeEntities(settings.title || 'CorePay Money');
	var description = htmlEntities.decodeEntities(settings.description || '');
	var icon = settings.icon || '';
	var createElement = wpElement.createElement;

	function Label() {
		return createElement(
			'span',
			{ className: 'corepay-money-blocks-label' },
			icon
				? createElement('img', {
					src: icon,
					alt: '',
					style: {
						width: '24px',
						height: '24px',
						marginRight: '8px',
						verticalAlign: 'middle'
					}
				})
				: null,
			createElement('span', null, title)
		);
	}

	function Content() {
		return description ? createElement('span', null, description) : null;
	}

	blocksRegistry.registerPaymentMethod({
		name: 'corepay_money',
		label: createElement(Label),
		content: createElement(Content),
		edit: createElement(Content),
		canMakePayment: function() {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	});
}());
