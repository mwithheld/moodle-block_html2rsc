<?php

require_once('../../config.php');
require_once($CFG->dirroot . '/lib/accesslib.php');
require_once($CFG->dirroot . '/mod/resource/lib.php');
require_once($CFG->dirroot . '/mod/resource/type/html/resource.class.php');

class html2rsc_processor {

    var $courseid = NULL;
    var $course_path = NULL;
    var $course_files_url = NULL;
    var $returnurl = NULL;
    var $processedHtmlFilesArr = array();
    var $logfilepath = __CLASS__;

    function start() {
        global $CFG;
        try {
            $this->courseid = required_param('courseid', PARAM_INT);
            $this->course_files_url = $CFG->wwwroot . '/file.php/' . $this->courseid . '/';
            $this->returnurl = $_SERVER['HTTP_REFERER'];

            if ($data = $this->check_security()) {
                $this->course_path = $CFG->dataroot . '/' . $this->courseid;
                $starting_filepath = clean_param($this->course_path . '/' . $data->reference['value'], PARAM_PATH);
            }
            $this->logfilepath = $this->course_path . '/' . $this->logfilepath . '.html';
            $this->mtrace("Starting path=$starting_filepath<br />\n");
            $this->mtrace("Course files url={$this->course_files_url}<br />\n");

            $this->migrate_file($starting_filepath);
        } catch (Exception $e) {
            error($e->getMessage);
        }
        $this->mtrace("A log file was written to {$this->logfilepath}");
        print_continue('');
    }

    function mtrace($string, $eol="\n", $sleep=0) {
        ob_start();
        mtrace($string, $eol, $sleep);
        $string = ob_get_flush();
        file_put_contents($this->logfilepath, $string, FILE_APPEND);
    }

    function check_security() {
        if (!confirm_sesskey()) {
            error('Invalid session key');
            return false;
        }

        $data = data_submitted();
        if (!$data) {
            error('No data submitted');
            return false;
        }

        $context = get_context_instance(CONTEXT_COURSE, $data->courseid);
        if (!has_capability('moodle/course:update', $context)) {
            error('You are not allowed to do that here');
            return false;
        }

        if (!$course = get_record('course', 'id', $data->courseid)) {
            error('Course ID was incorrect');
            return false;
        }

        return $data;
    }

    function html_file_to_dom($filepath) {
        $html = new DOMDocument();
        $html->loadHtmlFile($filepath);
        return $html;
    }

    /**
     * @source http://www.php-scripts.com/20060714/89/
     */
    function random_color() {
        $color = dechex(rand(0, 15)) . dechex(rand(0, 15)) . dechex(rand(0, 15)) . dechex(rand(0, 15)) . dechex(rand(0, 15)) . dechex(rand(0, 15));
        return $color;
    }

    function migrate_file($targetFile) {
        if (!file_exists($targetFile)) {
            $this->mtrace(__FUNCTION__ . __LINE__ . '::' . "The file does not exist: $targetFile<br />\n");
            return false;
        }

        $filetype = exec("file -i -b " . escapeshellarg($targetFile));
        $is_html = stristr($filetype, '/html') !== false;
        if (!$is_html) {
            $this->mtrace("That's not an HTML file ($filetype): $targetFile<br />\n");
            return false;
        }


        //don't process an HTML file twice - shouldn't get here anyway as the check is below
        if (!empty($this->processedHtmlFilesArr[$targetFile])) {
            $this->mtrace("Processing file $targetFile with title=$title: This file has already been processed: $targetFile; returning module with resourceid={$this->processedHtmlFilesArr[$targetFile]->coursemodule}<br />\n");
            return $this->processedHtmlFilesArr[$targetFile];
        }

        $this->mtrace("<div style=\"padding-left:24px; border:1px solid #" . $this->random_color() . "\">");
        $this->mtrace("<hr><h2>Working on file: $targetFile</h2><hr>\n");

        //find location relative to course root
        //$path_from_course_root = $this->get_path_from_course_root($targetFile);


        /**
         * Parse the HTML file into a DOM so it's easy to work with.
         * @ref http://kore-nordmann.de/blog/0081_parse_html_extract_data_from_html.html
         *
         * It even works for websites, which do not pass validators, but throw a lot of errors.
         *
         * The function libxml_use_internal_errors( true ) tells the libxml to not expose
         * DOM warnings and errors through PHPs error reporting system, but store them internally.
         * All validation errors can be requested later using the libxml_get_errors() function.
         * We also clear the yet occurred errors, so we get a clean new list - if something happened before.
         */
        $oldSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        //get the .html file HTML code
        $htmlDomDoc = $this->html_file_to_dom($targetFile);

        $title = $this->get_title($htmlDomDoc, pathinfo($targetFile, PATHINFO_FILENAME));

        $this->mtrace("Got the DOM for filepath=$targetFile with title=$title<br />\n--<br />\n");

        /**
         * Resources
         *
         * -Find resources (e.g. images, PDFs)
         * -Check they exist
         * -If not exist, leave it alone
         * -Rewrite url to <wwwroot>/file.php/<courseid>/<path>
         */
        /**
         * Links to other .html files in this course
         *
         * -Find relative links (a href's) to other .html files
         * -Check the linked file exists in the location specified relative to the file to be converted
         * -Recursively convert that file to a resource, returning the resourceid
         * -Rewrite the links to point to the returned resourceid
         */
        $xpathStrArr = array('//img' => 'src', '//@background' => 'background', '//a' => 'href');
        foreach ($xpathStrArr as $xpathStr => $targetAttribute) {
            $xpath = new DOMXPath($htmlDomDoc);
            $domNodeList = $xpath->query($xpathStr);
            foreach ($domNodeList as $domNode) {
                $uri_path = filter_var(trim($domNode->getAttribute($targetAttribute), FILTER_VALIDATE_URL));

                //ignore <a name=""> and empty references
                if (empty($uri_path)) {
                    continue;
                }

                $this->mtrace("I found a link path: {$htmlDomDoc->saveXML($domNode)}: {$uri_path}<br />\n");

                if ($this->is_absolute_url($uri_path)) {
                    $this->mtrace("It's a full URL, leave the link alone<br />\n--<br />\n");
                    continue;
                }

                $uri_path = $this->rel_url_to_abs_path($uri_path, dirname($targetFile));
                $this->mtrace("When uri is converted to a course path I get: $uri_path<br />\n");

                if (!$link_full_filepath = $this->url_is_file_in_course($uri_path)) {
                    $this->mtrace("It's not a file in the current course (e.g. a full URL), leave the link alone<br />\n--<br />\n");
                    continue;
                }

                //anchors get special treatment, so process all non-anchors first: just rewrite the url
                if (stripos($xpathStr, '//a') === FALSE) {
                    $absolute_link = $this->fullpath_to_url($uri_path, $this->course_files_url);
                    $domNode->setAttribute($targetAttribute, $absolute_link);
                    $htmlDomDoc->saveXML($domNode);
                    $this->mtrace("It's not an anchor - just rewrite the url to $absolute_link<br />\n--<br />\n");
                    continue;
                }

                //By now we know (1) it's an anchor; and (2) it's an existing course-local file
                //We'll need to migrate that file to a resource, too
                $filetype = exec("file -i -b " . escapeshellarg($link_full_filepath));
                $is_html = stristr($filetype, '/html') !== false;
                $this->mtrace("Is it an HTML file ($filetype)?: " . ($is_html ? 'yes' : 'no') . "<br />\n");

                if (!$is_html) {
                    $absolute_link = $this->fullpath_to_url($uri_path, $this->course_files_url);
                    $domNode->setAttribute($targetAttribute, $absolute_link);
                    $htmlDomDoc->saveXML($domNode);
                    $this->mtrace("It's not an HTML file, just rewrite the URL to $absolute_link<br />\n--<br />\n");
                    continue;
                }

                $this->mtrace("It's a local HTML file link and exists, so maybe I should recurse into it<br />\n");
                flush();
                ob_flush();

                //This linked file has already been processed, so just fix this url
                if (array_key_exists($link_full_filepath, $this->processedHtmlFilesArr) && !empty($this->processedHtmlFilesArr[$link_full_filepath])) {
                    $linked_mod = $this->processedHtmlFilesArr[$link_full_filepath];
                    $domNode->setAttribute($targetAttribute, $this->make_resource_uri($linked_mod->coursemodule));
                    $htmlDomDoc->saveXML($domNode);
                    $this->mtrace("This file has already been processed: $link_full_filepath; just rewrite the url with the resource id={$linked_mod->coursemodule}<br />\n-<br />\n");
                    continue;
                }

                if ($link_full_filepath != $targetFile) {
                    $linked_mod = $this->migrate_file($link_full_filepath);
                    $this->mtrace("Back from recursion for {$htmlDomDoc->saveXML($domNode)}: {$uri_path} with title=$title<br />\n");
                    $this->processedHtmlFilesArr[$link_full_filepath] = $linked_mod;
                    $domNode->setAttribute($targetAttribute, $this->make_resource_uri($linked_mod->coursemodule));
                } else {
                    //Don't recurse into the link for the current file!
                    //BUT I need a resourceid to rewrite the link

                    $this->mtrace("Processing file $targetFile with title=$title: But it's the current file, so skip recursion.  Create the resource for file $targetFile with title=$title so I have a resourceid I can use to rewrite the url; the resource will be updated more later.<br />\n");
                    $html = '';
                    $thiscurrentfile_mod = $this->create_resource($title, $html);
                    $this->processedHtmlFilesArr[$link_full_filepath] = $thiscurrentfile_mod;
                    $domNode->setAttribute($targetAttribute, $this->make_resource_uri($thiscurrentfile_mod->coursemodule));
                }

                $this->mtrace("Saved this to the DOM<br />\n{$htmlDomDoc->saveXML($domNode)}: {$uri_path}<hr>\n");
            } //end foreach DomNode
        } //end foreach XPath
        //write the fixed up body contents to the DOM
        $xpath = new DOMXPath($htmlDomDoc);
        //$this->mtrace('Found HTML:'.htmlentities($htmlDomDoc->saveHTML()));
        $domNodeList = $xpath->query('//body/*');
        $newDom = new DOMDocument;
        foreach ($domNodeList as $domElement) {
            $tempDomNode = $newDom->importNode($domElement, true);
            $newDom->appendChild($tempDomNode);
        }
        $html = $newDom->saveHTML();
        //$this->mtrace(__LINE__."::HTML is here<hr><PRE>".htmlentities($html)."</PRE><hr><br />\n");
        $html = $this->convert_special_chars($html);
        //$this->mtrace(__LINE__."::HTML is here<hr><PRE>".htmlentities($html)."</PRE><hr><br />\n");
        $html = clean_param($html, PARAM_CLEANHTML);
        //$this->mtrace(__LINE__."::HTML is here<hr><PRE>".htmlentities($html)."</PRE><hr><br />\n");
        $html = addslashes($html);
        //$this->mtrace(__LINE__."::HTML is here<hr><PRE>".htmlentities($html)."</PRE><hr><br />\n");

        //save DOM changes to the mod/resource
        if (empty($thiscurrentfile_mod)) {
            //the resource has not yet been created, so do so now -- use the body tag HTML
            //$this->mtrace('About to create resource using HTML: '.htmlentities($html));
            $thiscurrentfile_mod = $this->create_resource($title, $html);
        } else {
            //a resouce exists, so we're adding to it.  Use the 
            //$this->mtrace('About to update the resource html: '.htmlentities($save_this_html));
            $thiscurrentfile_mod->alltext = $html;
        }

        $this->mtrace("Processing file $targetFile with title=$title: Updated module {$thiscurrentfile_mod->name}<br />\n");
        $thiscurrentfile_mod = $this->update_resource($thiscurrentfile_mod);
        //$this->mtrace("Processing file $targetFile with title=$title: Updated module {$thiscurrentfile_mod->name}<br />\n");
        //save OR update
        $this->processedHtmlFilesArr[$targetFile] = $thiscurrentfile_mod;

        //In the end you should always reset the libxml error reporting to is original state to not unintentionally mess with other parts of the application
        libxml_clear_errors();
        libxml_use_internal_errors($oldSetting);

        $this->mtrace("Processing file $targetFile with title=$title: About to return module with name={$thiscurrentfile_mod->name}; HTML is here<hr><PRE>".htmlentities($html)."<PRE><hr><br />\n");
        $this->mtrace("</div>");
        return $thiscurrentfile_mod;
    }

    function is_valid_url($url) {
        if (!($url = @parse_url($url))) {
            return false;
        }

        if (isset($url['host']) AND $url['host'] != @gethostbyname($url['host'])) {
            return true;
        }

        return false;
    }

    function url_is_file_in_course($uri_path) {
        //don't accept any urls
        if ($this->is_absolute_url($uri_path)) {
            $this->mtrace(__FUNCTION__ . "::It's an absolute URL, so return false<br />\n");
            return false;
        }

        $real_path = trim($uri_path);
        //$this->mtrace(__FUNCTION__ . "::For $uri_path the realpath=$real_path<br />\n");
        //Is the first part of the realpath == course_root?
        if (stripos($real_path, $this->course_path) !== 0 /* purposely NOT != */) {
            $this->mtrace(__FUNCTION__ . "::The path $real_path is not in the course root {$this->course_path}, so return false<br />\n");
            return false;
        }

        //$link_full_filepath = $this->course_path . "/$uri_path";
        if (!file_exists($real_path)) {
            $this->mtrace(__FUNCTION__ . "::The file $real_path does not exist, so return false<br />\n");
            return false;
        }

        return $real_path;
    }

    function make_resource_uri($resourceid) {
        global $CFG;
        $new_uri = $CFG->wwwroot . "/mod/resource/view.php?id=$resourceid";
        return $new_uri;
    }

    function get_title(DomDocument $htmlDomDoc, $filename='') {
        $xpathStrArr = array('//h1', '//title', '//div[text()][1]');
        foreach ($xpathStrArr as $xpathStr) {
            $xpath = new DOMXPath($htmlDomDoc);
            $domNodeList = $xpath->query($xpathStr);
            foreach ($domNodeList as $node) {
                $text = $htmlDomDoc->saveXML($node);
                $text = strip_tags($text);
                //$this->mtrace("Found a title=$text<hr>\n");
                if (!empty($text)) { //ignore <a name="">
                    return trim(clean_text($text, FORMAT_PLAIN));
                }
            }
        }

        //if no name found, return filename (no extension - requires PHP 5.2), otherwise use a fixed string
        if (!empty($filename)) {
            return str_replace('_', ' ', clean_filename($filename));
        } else {
            return 'Unknown title';
        }
    }

    /**
     * @source Adapted from http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
     */
    function rel_url_to_abs_path($rel, $base) {

        /* queries and anchors */
        if ($rel[0] == '#' || $rel[0] == '?') {
            return $base;
        }

        /* parse base URL and convert to local variables:
          $scheme, $host, $path */
        //extract(parse_url($base));

        /* remove non-directory element from path */
        //$path = preg_replace('#/[^/]*$#', '', $path);
        $path = $base;

        /* destroy path if relative url points to root */
        /* dirty absolute URL */
        if ($rel[0] == '/') {
            $abs = "{$this->course_path}/$rel";
        } else {
            $abs = "$path/$rel";
        }

        /* replace '//' or '/./' or '/foo/../' with '/' */
        $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
        for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {
            //it's the preg_replace that does the work
        }

        /* absolute URL is ready! */
        return str_ireplace("../", "", $abs);
    }

    function set_resource_common_properties(&$mod) {
        $mod->windowpopup = 0;
        $mod->popup = 0;
        if (!isset($mod->instance)) {
            if (isset($mod->coursemodule)) {
                $mod->instance = $mod->coursemodule;
            } else {
                $mod->instance = '';
                $mod->coursemodule = '';
            }
        }
    }

    function update_resource($mod) {
        $rsc = new resource_html();
        $this->set_resource_common_properties($rsc);
        $rsc->update_instance($mod);
        return $mod;
    }

    function create_resource($title, $html) {
        $mod = new resource_html();
        $this->set_resource_common_properties($mod);
        $mod->type = 'html';
        $mod->course = $this->courseid;
        $mod->blockdisplay = 1;
        $mod->name = addslashes(clean_param($title, PARAM_TEXT));
        $mod->summary = '';
        $mod->section = 0;
        $mod->visible = 1;
        $mod->alltext = addslashes(clean_param($html, PARAM_CLEANHTML));
        $resourceid = $mod->add_instance($mod);
        $this->mtrace("Created new HTML resource with title=$title; id=$resourceid<br />\n");

        $mod->coursemodule = $resourceid;
        $mod->instance = $resourceid;
        $mod->module = get_field('modules', 'id', 'name', 'resource');


        $mod->section = '';
        $cmid = add_course_module($mod);
        $this->mtrace("Created new course_module title=$title; id=$cmid<br />\n");
        $mod->coursemodule = $cmid;

        $sectionid = add_mod_to_section($mod);
        $this->mtrace("Added_module title=$title to a section with sectionid=$sectionid<br />\n");

        if (!set_field("course_modules", "section", $sectionid, "id", $mod->coursemodule)) {
            error("Could not update the course module with the correct section");
        }

        set_coursemodule_visible($mod->coursemodule, $mod->visible);

        //clean out the static var caching course modules so the next pull of course modules works
        $reset = 'reset';
        get_fast_modinfo($reset);

        return $mod;
    }

    function is_absolute_url($uri) {
        if (!$this->is_valid_url($uri))
            return false;

        $pos = 0;
        return substr($uri, $pos, 7) == 'http://'
        || substr($uri, $pos, 8) == 'https://'
        || substr($uri, $pos, 6) == 'ftp://'
        || substr($uri, $pos, 9) == 'mailto://';
    }

    function fullpath_to_url($fullpath, $base_url) {
        $uri = ltrim(str_ireplace($this->course_path, '', $fullpath), '/');
        $uri = rtrim($base_url, '/') . "/$uri";
        return $uri;
    }

    
    function convert_special_chars($string) {
        $search[] = "&acirc;&#128;&cent;"; //bullet
        $search[] = "&acirc;&#128;&#156;";  // left side double smart quote
        $search[] = '&acirc;&#128;&#157;';  // right side double smart quote
        $search[] = '&acirc;&euro;&tilde;';  // left side single smart quote
        $search[] = '&acirc;&euro;&trade;';  // right side single smart quote
        $search[] = '&acirc;&euro;&brvbar;';  // elipsis
        $search[] = '&acirc;&euro;&rdquo;';  // em dash
        $search[] = '&acirc;&euro;&ldquo;';  // en dash

        $replace[] = '&bull;';
        $replace[] = '"';
        $replace[] = '"';
        $replace[] = "'";
        $replace[] = "'";
        $replace[] = "...";
        $replace[] = "-";
        $replace[] = "-";

        return str_replace($search, $replace, $string);
    }

    /**
     * TODO: How to handle linked or embedded CSS ??
     */
    /**
     * TODO: How to handle linked or embedded JS ??
     */
    /**
     * TODO: What do I do with the html file once I'm done??
     */
}

print_header_simple('HTML2Rsc', "", '', "", "", true, "", navmenu($COURSE));

$processor = new html2rsc_processor();
$processor->start();
print_footer($COURSE);


