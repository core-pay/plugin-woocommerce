(function($) {
	'use strict';

	function emptyRow(fieldKey) {
		return '' +
			'<tr>' +
				'<td class="corepay-money-operator-handle">☰</td>' +
				'<td><input type="text" name="' + fieldKey + '[id][]" value="" placeholder="coreid result" /></td>' +
				'<td><input type="text" name="' + fieldKey + '[operator][]" value="" placeholder="operator name" /></td>' +
				'<td><button type="button" class="button corepay-money-remove-operator">Delete</button></td>' +
			'</tr>';
	}

	$(function() {
		var table = $('.corepay-money-operators');

		if (!table.length) {
			return;
		}

		table.find('tbody').sortable({
			handle: '.corepay-money-operator-handle',
			items: 'tr',
			cursor: 'move'
		});

		$('.corepay-money-add-operator').on('click', function() {
			var fieldKey = table.data('field-key');
			table.find('tbody').append(emptyRow(fieldKey));
		});

		table.on('click', '.corepay-money-remove-operator', function() {
			var rows = table.find('tbody tr');

			if (rows.length <= 1) {
				$(this).closest('tr').find('input').val('');
				return;
			}

			$(this).closest('tr').remove();
		});
	});
}(jQuery));
