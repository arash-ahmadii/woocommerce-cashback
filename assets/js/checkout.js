(function ($) {
	'use strict';

	function triggerCheckoutRefresh() {
		if (typeof $(document.body).trigger === 'function') {
			$(document.body).trigger('update_checkout');
		}
	}

	$(document).on('change', 'input[name="gs_use_cashback"]', function () {
		triggerCheckoutRefresh();
	});
})(jQuery);
