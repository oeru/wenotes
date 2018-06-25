/* created by Dave Lane, dave@oerfoundation.org, https://oeru.org */

var DEBUG = true; // set to false to disable debugging
var LOG = DEBUG ? console.log.bind(console) : function () {};
LOG('WENOTES DEBUG = true'); // only prints if DEBUG = true

// get the course tag, given that the course tag could contain a '-'
// the course tag is the set of terms between the 2nd and last '-'
function get_feed_details(id) {
    var ids = [];
    terms = id.split('-');
    ids['user_id'] = terms[1]; // second term is the User ID
    ids['site_id'] = terms[2]; // third term is the Site ID
    LOG('returning ids '+ids);
    return ids;
}

// jQuery seletors and related functions in that context
jQuery(document).ready(function() {
    var $ = jQuery;
    var ptime = 3000; // time to display
    var ftime = 1000; // time to fade
    LOG('wenotes-site', wenotes_site_data);

    // display success messages
    function success(user_id, site_id, messages) {
        LOG('success message');
        show_feedback(user_id, site_id);
        $('#feedback-'+user_id+'-'+site_id).addClass('success');
        set_feedback(user_id, site_id, messages);
        if (messages instanceof Array) { length = messages.length; }
        else { length = 1; }
        hide_feedback(user_id, site_id, length);
    }

    // display failure messages
    function failure(user_id, site_id, error) {
        LOG('failure message');
        show_feedback(user_id, site_id);
        $('#feedback-'+user_id+'-'+site_id).addClass('error');
        set_feedback(user_id, site_id, error);
        if (error instanceof Array) { length = error.length; }
        else { length = 1; }
        hide_feedback(user_id, site_id, length);
    }

    function set_feedback(user_id, site_id, msg) {
        $('#feedback-'+user_id+'-'+site_id).empty();
        if (msg instanceof Array) {
            LOG('this is an array');
            msg.forEach(function(entry) {
                LOG('showing message ', entry.message);
                $('#feedback-'+user_id+'-'+site_id).
                    append('<p class="wenotes-feedback">'+entry.message+'</p>');
            });
        } else {
            LOG('this is a scalar string');
            $('#feedback-'+user_id+'-'+site_id).append('<p>'+msg+'</p>');
        }
    }

    function show_feedback(user_id, site_id) {
        LOG('showing feedback');
        $('#row-'+user_id+'-'+site_id).removeClass('hidden');
        $('#feedback-'+user_id+'-'+site_id).removeClass('hidden');
        $('#row-'+user_id+'-'+site_id).show();
        $('#feedback-'+user_id+'-'+site_id).show();
    }

    function hide_feedback(user_id, site_id, num = 1) {
        LOG('hiding feedback - num ', num);
        $('#feedback-'+user_id+'-'+site_id).animate(
            {opacity: 0.9},
            {duration: ptime * num, complete: function() {
                $(this).hide(ftime);
            }, function() {
                $('#row-'+user_id+'-'+site_id).hide();
            }}
        );
    }

    // add/update a user's blog feed URL for a site
    //$('.wenotes-feed-alter').click(function() {
    $('.wenotes-form-cell').on('click','.wenotes-feed-alter', function() {
        ids = get_feed_details($(this).attr('id'));
        // get the value from the url input field
        url = $('#url-'+ids['user_id']+'-'+ids['site_id']).val();
        LOG('update blog feed URL for user '+ids['user_id']+' and site '+ids['site_id']+' to '+url);
        //LOG('wenotes_site = ', wenotes_site_data);
        is_add = false;
        if ($(this).hasClass('add')) {
            LOG('this is an add - if successful, replace the buttons');
            is_add = true;
        } else {
            LOG('this isn\'t an add');
        }
        if (url == '') {
            failure(ids['user_id'], ids['site_id'], 'You must set a URL value first.');
            return;
        }
        set_feedback(ids['user_id'], ids['site_id'], 'Processing...');
        show_feedback(ids['user_id'], ids['site_id']);
        $.ajax({
            type: 'POST',
            dataType: 'json',
            url: wenotes_site_data.ajaxurl,
            data: {
                'action': 'wenotes_alter',
                'site_nonce': wenotes_site_data.site_nonce,
                'url': url,
                'user_id': ids['user_id'],
                'site_id': ids['site_id'],
                'is_add': is_add
            },
            // successful ajax query
            success: function(data) {
                LOG('returned data ', data);
                if (data.hasOwnProperty('success')) {
                    LOG('success: data ', data);
                    if (data.hasOwnProperty('messages')) {
                        LOG('messages ', data.messages);
                        success(data.details.user_id, data.details.site_id, data.messages);
                    }
                    // if we have a new form, put it in place.
                    if (data.details.hasOwnProperty('new_form')) {
                        LOG('replacing add button with update and delete');
                        if (data.details.is_add) {
                            LOG('replacing add form');
                        } else {
                            LOG('replacing update/delete form');
                        }
                        // update the form, replacing the content of the td
                        $('#cell-'+data.details.user_id+'-'+data.details.site_id).
                                html(data.details.new_form);
                    }
                } else {
                    LOG('failure: data', data);
                    if (data.hasOwnProperty('errors')) {
                        LOG('errors ', data.errors);
                        failure(data.details.user_id, data.details.site_id, data.errors);
                    }
                }
            },
            // failed ajax query
            error: function(jqXHR, textStatus, errorThrown) {
                LOG('failure: jqXHR ', jqXHR);
                LOG('failure: textStatus ', textStatus);
                LOG('failure: errorThrown ', errorThrown);
            }
        });
    });

    // remove an user's existing blog feed URL for a site
    //$('.wenotes-feed-delete').click(function() {
    $('.wenotes-form-cell').on('click','.wenotes-feed-delete', function() {
        ids = get_feed_details($(this).attr('id'));
        // get the value from the url input field
        url = $('#url-'+ids['user_id']+'-'+ids['site_id']).val();
        set_feedback(ids['user_id'], ids['site_id'], 'Deleting '+url);
        LOG('remove blog feed URL for user '+ids['user_id']+' and site '+ids['site_id']);
        set_feedback(ids['user_id'], ids['site_id'], 'Processing...');
        show_feedback(ids['user_id'], ids['site_id']);
        $.ajax({
            type: 'POST',
            dataType: 'json',
            url: wenotes_site_data.ajaxurl,
            data: {
                'action': 'wenotes_delete',
                'site_nonce': wenotes_site_data.site_nonce,
                'user_id': ids['user_id'],
                'site_id': ids['site_id']
            },
            // successful ajax query
            success: function(data) {
                LOG('returned data ', data);
                if (data.hasOwnProperty('success')) {
                    if (data.hasOwnProperty('messages')) {
                        LOG('messages ', data.messages);
                        success(data.details.user_id, data.details.site_id, data.messages);
                    }
                    // if we have a new form, put it in place.
                    if (data.details.hasOwnProperty('new_form')) {
                        LOG('replacing add button with update and delete');
                        if (data.details.is_add) {
                            LOG('replacing add form');
                        } else {
                            LOG('replacing update/delete form');
                        }
                        // update the form, replacing the content of the td
                        $('#cell-'+data.details.user_id+'-'+data.details.site_id).
                                html(data.details.new_form);
                    }
                } else {
                    LOG('failure: data', data);
                    if (data.hasOwnProperty('errors')) {
                        LOG('errors ', data.errors);
                    }
                }
            },
            // failed ajax query
            error: function(jqXHR, textStatus, errorThrown) {
                LOG('Failure: jqXHR ', jqXHR);
                LOG('Failure: textStatus ', textStatus);
                LOG('Failure: errorThrown ', errorThrown);
            }
        });
    });

    // the end of the jQuery loop...
});
