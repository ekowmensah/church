// Fix for custom header dropdown trigger when using a <div> instead of <a> as the dropdown parent
$(document).ready(function() {
  // Forward click from the custom dropdown toggle to the actual dropdown
  $('#userDropdown').on('click', function(e) {
    e.preventDefault();
    var $dropdown = $(this).closest('.dropdown');
    if (!$dropdown.hasClass('show')) {
      $dropdown.addClass('show');
      $dropdown.find('.dropdown-menu').addClass('show');
    } else {
      $dropdown.removeClass('show');
      $dropdown.find('.dropdown-menu').removeClass('show');
    }
  });

  // Hide dropdown when clicking outside
  $(document).on('click', function(e) {
    var $dropdown = $('.nav-item.dropdown');
    if (!$dropdown.is(e.target) && $dropdown.has(e.target).length === 0) {
      $dropdown.removeClass('show');
      $dropdown.find('.dropdown-menu').removeClass('show');
    }
  });
});
