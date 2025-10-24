(function($){
  $(document).on('click', '#bo-cp-add-row', function(){
    var $tbody = $('#bo-cp-sections-rows');
    var $tpl = $(`
      <tr class="bo-cp-row">
        <td><input type="text" name="bo_cp_sections_key[]" placeholder="overview / love / career ..." style="width:100%"/></td>
        <td><input type="text" name="bo_cp_sections_title[]" placeholder="Section Title" style="width:100%"/></td>
        <td><textarea name="bo_cp_sections_content[]" rows="4" style="width:100%" placeholder="<p>HTML content...</p>"></textarea></td>
        <td><button type="button" class="button link-delete bo-cp-del-row">Delete</button></td>
      </tr>`);
    $tbody.append($tpl);
  });

  $(document).on('click', '.bo-cp-del-row', function(){
    $(this).closest('tr').remove();
  });
})(jQuery);