// Local handoff UI probe only; PHP refuses every attempt to process a payment.
(function () {
  var text = 'QA card handoff probe (no payments)';
  var label = wp.element.createElement('span', null, text);
  var content = wp.element.createElement('p', null, 'State-transfer check only. No card data or payments.');
  wc.wcBlocksRegistry.registerPaymentMethod({ name: 'wr_handoff_probe', label: label, content: content, edit: content, canMakePayment: function () { return true; }, ariaLabel: text, supports: { features: ['products'] } });
})();
