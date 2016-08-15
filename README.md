# WEnotes plugin for WordPress

This is a very quick rendering of
[WikiEducator Widget:WEnotes](http://WikiEducator.org/Widget:WEnotes)
and [WikiEducator Widget:WEnotesPost](http://WikiEducator.org/Widget:WEnotesPost)
as a WordPress plugin. It adds shortcodes with similar arguments to the widgets:

* [WEnotes tag="wikieducator" count="20"]
* [WEnotesPost tag="wikieducator" button="Post a WEnote"]

## Dependencies

The posting side of the plugin requires local copies (easily installed
as git submodules) of:

* [WEnotes](https://bitbucket.org/wikieducator/wenotes), the client-side
Javascript
* [Sag](http://saggingcouch.com/), CouchDB libraries for PHP and Javascript
(the PHP one is used here)

## Note

The current form of the plugin presumes that WordPress user logins are enabled
through the [OERu course](https://github.com/oeru/oeru_course) theme on
_Course dashboard_`... Appearance... Customize... Site Navigation... Show the login option?`
