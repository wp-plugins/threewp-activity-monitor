jQuery(document).ready(function($) {
	$("table.threewp_activity_monitor").parent().append( '<p class="3wp_am_invert_selection">Invert selection</p>' );
	
	$(".3wp_am_invert_selection").click( function(){
		$.each( $("table.threewp_activity_monitor input.checkbox"), function (index, item){
			$(item).attr('checked', ! $(item).attr('checked') );
		});
	});
});
