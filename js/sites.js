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
    LOG('wenotes-site', wenotes_site_data);

    // display success messages
    function success(user_id, site_id, messages) {

    }

    // display failure messages
    function failure(user_id, site_id, error) {
        $('#feedback-'+user_id+'-'+site_id).addClass('error');
        error.forEach(function(entry) {
            LOG('entry = ', entry);
            set_message(user_id, site_id, entry);
       });
    }

    function set_message(user_id, site_id, msg) {
        $('#row-'+user_id+'-'+site_id).show();
        $('#feedback-'+user_id+'-'+site_id).show();
        $('#feedback-'+user_id+'-'+site_id).html(msg);
    }

    // add/update a user's blog feed URL for a site
    $('.wenotes-feed-alter').click(function() {
        ids = get_feed_details($(this).attr('id'));
        // get the value from the url input field
        url = $('#url-'+ids['user_id']+'-'+ids['site_id']).val();
        LOG('update blog feed URL for user '+ids['user_id']+' and site '+ids['site_id']+' to '+url);
        //LOG('wenotes_site = ', wenotes_site_data);
        if (url == '') {
            failure(ids['user_id'], ids['site_id'], 'You must set a URL value first.');
            return;
        }
        $.ajax({
            type: 'POST',
            dataType: 'json',
            url: wenotes_site_data.ajaxurl,
            data: {
                'action': 'wenotes_alter',
                'site_nonce': wenotes_site_data.site_nonce,
                'url': url,
                'user_id': ids['user_id'],
                'site_id': ids['site_id']
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
    $('.wenotes-feed-delete').click(function() {
        ids = get_feed_details($(this).attr('id'));
        // get the value from the url input field
        url = $('#url-'+ids['user_id']+'-'+ids['site_id']).val();
        set_message(ids['user_id'], ids['site_id'], 'Deleting '+url);
        LOG('remove blog feed URL for user '+ids['user_id']+' and site '+ids['site_id']);
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
                    LOG('success: data ', data);
                    if (data.hasOwnProperty('messages')) {
                        LOG('messages ', data.messages);
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
