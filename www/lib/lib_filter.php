<?php
  #
  # lib_filter.txt
  #
  # A PHP HTML filtering library
  #
  # http://iamcal.com/publish/articles/php/processing_html/
  # http://iamcal.com/publish/articles/php/processing_html_part_2/
  #
  # By Cal Henderson <cal@iamcal.com>
  # This code is licensed under a Creative Commons Attribution-ShareAlike 2.5 License
  # http://creativecommons.org/licenses/by-sa/2.5/
  #
  # Thanks to Jang Kim for adding support for single quoted attributes
  # Thanks to Dan Bogan for dealing with entity decoding outside attributes
  #

  class lib_filter {

    var $tag_counts = [];

    #
    # tags and attributes that are allowed
    #

    var $allowed = [
      'a'      => ['href', 'target', 'title', 'rel'],
      'strong' => [],
      'em'     => [],
      'code'   => [],
      'u'      => [],
      'b'      => [],
      'i'      => [],
    ];


    #
    # tags which should always be self-closing (e.g. "<img />")
    #

    var $no_close = [];


    #
    # tags which must always have seperate opening and closing tags (e.g. "<b></b>")
    #

    var $always_close = [
      'a',
      'u',
      'b',
      'i',
      'em',
      'code',
      'strong',
    ];


    #
    # attributes which should be checked for valid protocols
    #

    var $protocol_attributes = [
      'src',
      'href',
    ];


    #
    # protocols which are allowed
    #

    var $allowed_protocols = [
      'http',
      'https',
      'ftp',
      'mailto',
    ];


    #
    # tags which should be removed if they contain no content (e.g. "<b></b>" or "<b />")
    #

    var $remove_blanks = [
      'a',
      'u',
      'b',
      'i',
      'em',
      'code',
      'strong',
    ];


    #
    # should we remove comments?
    #

    var $strip_comments = 1;


    #
    # should we try and make a b tag out of "b>"
    #

    var $always_make_tags = 1;


    #
    # should we allow dec/hex entities within the input?
    # if you set this to zero, '&#123;' will be converted to '&amp;#123;'
    #

    var $allow_numbered_entities = 1;


    #
    # these non-numeric entities are allowed. non allowed entities will be
    # converted from '&foo;' to '&amp;foo;'
    #

    var $allowed_entities = [
      'amp',
      'gt',
      'lt',
      'quot',
    ];


    #
    # should we convert dec/hex entities in the general doc (not inside protocol attribute)
    # into raw characters? this is important if you're planning on running autolink on
    # the output, to make it easier to filter out unwanted spam URLs. without it, an attacker
    # could insert a working URL you'd otherwise be filtering (googl&#65;.com would avoid
    # a string-matching spam filter, for instance). this only affects character codes below
    # 128 (that is, the ASCII characters).
    #
    # this setting overrides $allow_numbered_entities
    #

    var $normalise_ascii_entities = 0;


    #####################################################################################

    #
    # this is the main entry point - pass your document to be filtered into here
    #

    function go($data){

      $this->tag_counts = [];

      $data = $this->escape_comments($data);
      $data = $this->balance_html($data);
      $data = $this->check_tags($data);
      $data = $this->process_remove_blanks($data);
      $data = $this->cleanup_non_tags($data);

      return $data;
    }


    #####################################################################################

    #
    # the first step is to make sure we don't have HTML inside the comments.
    # comment are (optionally) stripped later on, but this ensures we don't
    # waste time matching stuff inside them.
    #

    function escape_comments($data){

      $data = preg_replace_callback("/<!--(.*?)-->/s", [$this, 'escape_comments_inner'], $data);

      return $data;
    }

    function escape_comments_inner($m){
      return '<!--'.HtmlSpecialChars($this->StripSingle($m[1])).'-->';
    }


    #####################################################################################

    function balance_html($data){

      if ($this->always_make_tags){

        #
        # try and form html
        #

        $data = preg_replace("/>>+/", ">", $data);
        $data = preg_replace("/<<+/", "<", $data);
        $data = preg_replace("/^>/", "", $data);
        $data = preg_replace("/<([^>]*?)(?=<|$)/", "<$1>", $data);
        $data = preg_replace("/(^|>)([^<]*?)(?=>)/", "$1<$2", $data);

      }else{

        #
        # escape stray brackets
        #

        $data = preg_replace("/<([^>]*?)(?=<|$)/", "&lt;$1", $data);
        $data = preg_replace("/(^|>)([^<]*?)(?=>)/", "$1$2&gt;<", $data);

        #
        # the last regexp causes '<>' entities to appear
        # (we need to do a lookahead assertion so that the last bracket can
        # be used in the next pass of the regexp)
        #

        $data = str_replace('<>', '', $data);
      }

      #echo "::".HtmlSpecialChars($data)."<br />\n";

      return $data;
    }


    #####################################################################################

    function check_tags($data){

      $data = preg_replace_callback("/<(.*?)>/s", [$this, 'check_tags_inner'], $data);

      foreach (array_keys($this->tag_counts) as $tag){
        for ($i=0; $i<$this->tag_counts[$tag]; $i++){
          $data .= "</$tag>";
        }
      }

      return $data;
    }

    function check_tags_inner($m){

      return $this->process_tag($this->StripSingle($m[1]));
    }

    #####################################################################################

    function process_tag($data){

      # ending tags
      if (preg_match("/^\/([a-z0-9]+)/si", $data, $matches)){
        $name = StrToLower($matches[1]);
        if (in_array($name, array_keys($this->allowed))){
          if (!in_array($name, $this->no_close)){
            if (isset($this->tag_counts[$name])){
              $this->tag_counts[$name]--;
              return '</'.$name.'>';
            }
          }
        }else{
          return '';
        }
      }

      # starting tags
      if (preg_match("/^([a-z0-9]+)(.*?)(\/?)$/si", $data, $matches)){

        $name = StrToLower($matches[1]);
        $body = $matches[2];
        $ending = $matches[3];
        if (in_array($name, array_keys($this->allowed))){
          $params = "";
          preg_match_all("/([a-z0-9]+)=([\"'])(.*?)\\2/si", $body, $matches_2, PREG_SET_ORDER);    # <foo a="b" />
          preg_match_all("/([a-z0-9]+)(=)([^\"\s']+)/si", $body, $matches_1, PREG_SET_ORDER);    # <foo a=b />
          preg_match_all("/([a-z0-9]+)=([\"'])([^\"']*?)\s*$/si", $body, $matches_3, PREG_SET_ORDER);  # <foo a="b />
          $matches = array_merge($matches_1, $matches_2, $matches_3);

          foreach ($matches as $match){
            $pname = StrToLower($match[1]);
            if (in_array($pname, $this->allowed[$name])){
              $value = $match[3];
              if (in_array($pname, $this->protocol_attributes)){
                $value = $this->process_param_protocol($value);
              }else{
                $value = str_replace('"', '&quot;', $value);
              }
              $params .= " $pname=\"$value\"";
            }
          }
          if (in_array($name, $this->no_close)){
            $ending = ' /';
          }
          if (in_array($name, $this->always_close)){
            $ending = '';
          }
          if (!$ending){
            if (isset($this->tag_counts[$name])){
              $this->tag_counts[$name]++;
            }else{
              $this->tag_counts[$name] = 1;
            }
          }
          if ($ending){
            $ending = ' /';
          }
          return '<'.$name.$params.$ending.'>';
        }else{
          return '';
        }
      }

      # comments
      if (preg_match("/^!--(.*)--$/si", $data)){
        if ($this->strip_comments){
          return '';
        }else{
          return '<'.$data.'>';
        }
      }


      # garbage, ignore it
      return '';
    }


    #####################################################################################

    function process_param_protocol($data){

      $data = $this->validate_entities($data, 1);

      if (preg_match("/^([^:]+)\:/si", $data, $matches)){
        if (!in_array($matches[1], $this->allowed_protocols)){
          $data = '#'.substr($data, strlen($matches[1])+1);
        }
      }

      return $data;
    }


    #####################################################################################

    #
    # this function removes certain tag pairs if they have no content. for instance,
    # 'foo<b></b>bar' is converted to 'foobar'.
    #

    function process_remove_blanks($data){

      if (count($this->remove_blanks)){

        $tags = implode('|', $this->remove_blanks);
        while (1){
          $len = strlen($data);
          $data = preg_replace("/<({$tags})(\s[^>]*)?(><\\/\\1>|\\/>)/", '', $data);
          if ($len == strlen($data)) break;
        }
      }

      return $data;
    }


    #####################################################################################

    #
    # given some HTML input, find out if the non-HTML part is too
    # shouty. that is, does it solely consist of capital letters.
    # if so, make it less shouty.
    #

    function fix_case($data){

      #
      # extract only the (latin) letters in the string
      #

      $data_notags = Strip_Tags($data);
      $data_notags = preg_replace('/[^a-zA-Z]/', '', $data_notags);


      #
      # if there are less than 5, just allow it as-is
      #

      if (strlen($data_notags)<5){
        return $data;
      }


      #
      # if there are lowercase letters somewhere, allow it as-is
      #

      if (preg_match('/[a-z]/', $data_notags)){
        return $data;
      }

      #
      # we have more than 5 letters and they're all capitals. we
      # want to case-normalize.
      #

      return preg_replace_callback(
        "/(>|^)([^<]+?)(<|$)/s",
        [$this, 'fix_case_inner'],
        $data
      );
    }

    #####################################################################################

    #
    # given a block of non-HTML, filter it for shoutyness by lowercasing
    # the whole thing and then capitalizing the first letter of each
    # 'sentance'.
    #

    function fix_case_inner($m){

      $data = StrToLower($m[2]);

      $data = preg_replace_callback(
        '/(^|[^\w\s\';,\\-])(\s*)([a-z])/',
        [$this, 'fix_case_inner_do'],
        $data
      );

      return $m[1].$data.$m[3];
    }

    function fix_case_inner_do($m){
      return $m[1].$m[2].StrToUpper($m[3]);
    }


    #####################################################################################

    #
    # this function is called in two places - inside of each href-like
    # attributes and then on the whole document. it's job is to make
    # sure that anything that looks like an entity (starts with an
    # ampersand) is allowed, else corrects it.
    #

    function validate_entities($data, $in_attribute){

      #
      # turn ascii characters into their actual characters, if requested.
      # we need to always do this inside URLs to avoid people using
      # entities or URL escapes to insert 'javascript:' or something like
      # that. outside of attributes, we optionally filter entities to
      # stop people from inserting text that they shouldn't (since it might
      # make it into a clickable URL via lib_autolink).
      #

      if ($in_attribute || $this->normalise_ascii_entities){
        $data = $this->decode_entities($data, $in_attribute);
      }


      #
      # find every remaining ampersand in the string and check if it looks
      # like it's an entity (then validate it) or if it's not (then escape
      # it).
      #

      $data = preg_replace_callback(
        '!&([^&;]*)(?=(;|&|$))!',
        [$this, 'validate_entities_inner'],
        $data
      );


      #
      # Make sure we encode quotes so that you can't turn:
      # <img src='"onerror="alert()'>
      # into:
      # <img src=""onerror="alert()">
      #

      $data = str_replace('"', '&quot;', $data);

      return $data;
    }

    function validate_entities_inner($m){
      return $this->check_entity($this->StripSingle($m[1]), $this->StripSingle($m[2]));
    }


    #####################################################################################

    #
    # this function comes last in processing, to clean up data outside of tags.
    #

    function cleanup_non_tags($data){

      return preg_replace_callback(
        "/(>|^)([^<]+?)(<|$)/s",
        [$this, 'cleanup_non_tags_inner'],
        $data
      );

    }

    function cleanup_non_tags_inner($m){

      #
      # first, deal with the entities
      #

      $m[2] = $this->validate_entities($m[2], 0);


      #
      # find any literal quotes outside of tags and replace them
      # with &quot;. we call it last thing before returning.
      #

      $m[2] = str_replace("\"", "&quot;", $m[2]);



      return $m[1].$m[2].$m[3];
    }


    #####################################################################################

    #
    # this function gets passed the 'inside' and 'end' of a suspected
    # entity. the ampersand is not included, but must be part of the
    # return value. $term is a look-ahead assertion, so don't return
    # it.
    #

    function check_entity($preamble, $term){

      #
      # if the terminating character is not a semi-colon, treat
      # this as a non-entity
      #

      if ($term != ';'){

        return '&amp;'.$preamble;
      }


      #
      # if it's an allowed entity, go for it
      #

      if ($this->is_valid_entity($preamble)){

        return '&'.$preamble;
      }


      #
      # not an allowed antity, so escape the ampersand
      #

      return '&amp;'.$preamble;
    }


    #####################################################################################

    #
    # this function determines whether the body of an entity (the
    # stuff between '&' and ';') is valid.
    #

    function is_valid_entity($entity){

      #
      # numeric entity. over 127 is always allowed, else it's a pref
      #

      if (preg_match('!^#([0-9]+)$!i', $entity, $m)){

        return ($m[1] > 127) ? 1 : $this->allow_numbered_entities;
      }


      #
      # hex entity. over 127 is always allowed, else it's a pref
      #

      if (preg_match('!^#x([0-9a-f]+)$!i', $entity, $m)){

        return (hexdec($m[1]) > 127) ? 1 : $this->allow_numbered_entities;
      }



      if (in_array($entity, $this->allowed_entities)){

        return 1;
      }

      return 0;
    }

    #####################################################################################

    #
    # within attributes, we want to convert all hex/dec/url escape sequences into
    # their raw characters so that we can check we don't get stray quotes/brackets
    # inside strings. within general text, we decode hex/dec entities.
    #

    function decode_entities($data, $in_attribute=1){

      $data = preg_replace_callback('!(&)#(\d+);?!', [$this, 'decode_dec_entity'], $data);
      $data = preg_replace_callback('!(&)#x([0-9a-f]+);?!i', [$this, 'decode_hex_entity'], $data);

      if ($in_attribute){
        $data = preg_replace_callback('!(%)([0-9a-f]{2});?!i', [$this, 'decode_hex_entity'], $data);
      }

      return $data;
    }

    function decode_hex_entity($m){

      return $this->decode_num_entity($m[1], hexdec($m[2]));
    }

    function decode_dec_entity($m){

      return $this->decode_num_entity($m[1], intval($m[2]));
    }


    #####################################################################################

    #
    # given a character code and the starting escape character (either '%' or '&'),
    # return either a hex entity (if the character code is non-ascii), or a raw
    # character. remeber to escape XML characters!
    #

    function decode_num_entity($orig_type, $d){

      if ($d < 0){ $d = 32; } # treat control characters as spaces

      #
      # don't mess with high characters - what to replace them with is
      # character-set independant, so we leave them as entities. besides,
      # you can't use them to pass 'javascript:' etc (at present)
      #

      if ($d > 127){
        if ($orig_type == '%'){ return '%'.dechex($d); }
        if ($orig_type == '&'){ return "&#$d;"; }
      }


      #
      # we want to convert this escape sequence into a real character.
      # we call HtmlSpecialChars() incase it's one of [<>"&]
      #

      return HtmlSpecialChars(chr($d));
    }


    #####################################################################################

    function StripSingle($data){
      return str_replace(['\\"', "\\0"], ['"', chr(0)], $data);
    }

    #####################################################################################

  }
