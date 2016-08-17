(function ($) {

  Drupal.behaviors.datatransLightbox = {
    attach: function (context, settings) {

      // Register a trigger on the payment button, use once() to avoid
      // multiple triggers.
      $('#paymentButton', context).once('datatrans', function() {
        $(this).click(function () {
          var disableSubmit = function(e) {
            e.preventDefault();
          };

          $('form').bind('submit', disableSubmit);

          Datatrans.startPayment({
            // Use the class selector here and not the id selector because of
            // cloned payment button.
            'form': '.checkout-continue[data-merchant-id]',
            'closed': function() {
              // Commerce checkout js will clone and hide original pay button
              // with disabled one (disable multiple clicks) on payment button
              // click, so we need to remove disabled button and show original
              // pay button again.
              // @see commerce_checkout.js
              $(this.form + '[disabled]').remove();
              $(this.form).show();

              // Also re-enable the form again, in case the user wants to proceed
              // with another payment option.
              $('form').unbind('submit', disableSubmit);
            }
          });
        });

        // Hides continue button if Datatrans payment method selected.
        if ($('#paymentButton').length) {
          $('#edit-buttons #edit-continue').hide();
          $('#edit-buttons .button-operator').hide();
        }
      });

      // Shows continue button for any other payment method.
      if (!$('#paymentButton').length) {
        $('#edit-buttons #edit-continue').show();
        $('#edit-buttons .button-operator').show();
      }
    }
  };

})(jQuery);
