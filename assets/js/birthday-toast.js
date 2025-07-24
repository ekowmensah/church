// Show a Bootstrap 4 toast for member birthday
$(function() {
  var toast = $('#birthdayToast');
  if (toast.length) {
    setTimeout(function() {
      toast.toast('show');
    }, 700); // delay for smoothness
  }
});
