(function($) {
   $(function () {
      var $trackingNumber = $('#pilipayTrackingNumber');
      var $updateBtn = $('#pilipayUpdateTrackingNumber');

      var isUpdating = false;

      $updateBtn.on('click', function () {
         if (isUpdating) {
            return;
         }

         isUpdating = true;
         $updateBtn.text('Updating...');

         $.post($updateBtn.attr('data-target-url'), {
            trackingNumber: $trackingNumber.val()
         }).done(function (responseText) {
            try{
               var response = JSON.parse(responseText) || {
                      errorCode: 600,
                      errorMsg: "Invalid response format! It should be a vaild JSON object."
                   };

               if (response.success){
                  $updateBtn.text('Updated successfully!');
               } else {
                  $updateBtn.text('Failed to update. Click here to try again.');
                  console.error("Update tracking number failed, detail: \n" + response.errorCode + ": " + response.errorMsg);
               }
            } catch (e){
               $updateBtn.text('Failed to update. Click here to try again.');
               console.error("Update tracking number failed, detail: %o\n response text: " + responseText, e);
            }
         }).fail(function () {
            $updateBtn.text('Failed to update. Click here to try again.');
            console.error("Update tracking number failed due to network reasons.");
         }).always(function () {
            isUpdating = false;
         });
      });
   });
})(window.jQuery);