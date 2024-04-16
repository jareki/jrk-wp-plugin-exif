<?php
    /**
     * @package show-exif
     * @version 0.1
     */
    /*
     Plugin Name: Show Exif
     Description: Adds EXIF Tags below each image
     Author: Jarek Izotov
     Version: 0.1
     */
    
    add_filter('the_content', 'exif_figure_content');
    add_filter('wp_read_image_metadata', 'filter_add_exif','',3);
    add_action('wp_enqueue_scripts', 'callback_for_setting_up_scripts');

    function callback_for_setting_up_scripts()
    {
        $plugin_url = plugin_dir_url( __FILE__ );
        wp_enqueue_style('exif-style', $plugin_url . 'style.css');
    }
    
    //******************************************************************************************
    // this hook function adds also the exif tag "make" in the database of each uploaded image
    //******************************************************************************************
    function filter_add_exif($meta, $file, $sourceImageType)
    {
        if ( is_callable('exif_read_data') &&
            in_array($sourceImageType, apply_filters('wp_read_image_metadata_types', array(IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM)) ) )
        {
            $exif = @exif_read_data( $file );
            
            if (!empty($exif['Make']))      $meta['make'] = $exif['Make'] ;
			
            return $meta;
        }
    }
    
    //******************************************************************************************
    // find the attachment id of a given image url
    //******************************************************************************************
    function get_attachment_id( $url )
    {
        $dir = wp_upload_dir();
        
        // baseurl never has a trailing slash
        if ( false === strpos( $url, $dir['baseurl'] . '/' ) ) {
            // URL points to a place outside of upload directory
            return false;
        }
        
        $file  = basename( $url );
        $query = array(
                       'post_type'  => 'attachment',
                       'fields'     => 'ids',
                       'meta_query' => array(
                                             array(
                                                   'value'   => $file,
                                                   'compare' => 'LIKE',
                                                   ),
                                             )
                       );
        
        $query['meta_query'][0]['key'] = '_wp_attached_file';
        
        // query attachments
        $ids = get_posts( $query );
        
        if ( ! empty( $ids ) ) {
            
            foreach ( $ids as $id ) {
                
                // first entry of returned array is the URL
                if ( $url === array_shift( wp_get_attachment_image_src( $id, 'full' ) ) )
                    return $id;
            }
        }
        
        $query['meta_query'][0]['key'] = '_wp_attachment_metadata';
        
        // query attachments again
        $ids = get_posts( $query );
        
        if ( empty( $ids) )
            return false;
        
        foreach ( $ids as $id ) {
            
            $meta = wp_get_attachment_metadata( $id );
            
            foreach ( $meta['sizes'] as $size => $values ) {
                
                if ( $values['file'] === $file && $url === array_shift( wp_get_attachment_image_src( $id, $size ) ) )
                    return $id;
            }
        }
        
        return false;
    }
    
    //******************************************************************************************
    // process each found <img> tag in the page, and try to add a line with the exif data
    //******************************************************************************************
    function exif_tags_images_process($matches)
    {
        // if <img> tag contains "noexif" than do nothing
        if(preg_match('/noexif/', $matches[0], $noexif) )
            return $matches[0];

        // try to find id in class value
        if(preg_match('/wp-image-([0-9]+)/', $matches[0], $idMatches) )
            $id = $idMatches[1];
        else
        {
            // extract url of image from src property of <img> tag
            if(!preg_match('/src="([^"?]*)/', $matches[0], $urlMatches) )
                return $matches[0];
            
            // find the attachment id of this image url
            $id = get_attachment_id($urlMatches[1]);
        }
        
        // read the meta data of the found attachment id out of the database
        $imgmeta = wp_get_attachment_metadata( $id );
        
        // check if there are any valid exif data
        if( !isset($imgmeta['image_meta']) || !isset($imgmeta['image_meta']['camera']) || $imgmeta['image_meta']['camera'] == '')
            return $matches[0];

        // get each exif tag in a proper format
        
        $pcamera = $imgmeta['image_meta']['camera'];
        
        // Convert the shutter speed retrieve from database to fraction
        
        if(isset($imgmeta['image_meta']['shutter_speed']))
        {
            if ((1 / $imgmeta['image_meta']['shutter_speed']) > 1)
            {
                if ((number_format((1 / $imgmeta['image_meta']['shutter_speed']), 1)) == 1.3
                    or number_format((1 / $imgmeta['image_meta']['shutter_speed']), 1) == 1.5
                    or number_format((1 / $imgmeta['image_meta']['shutter_speed']), 1) == 1.6
                    or number_format((1 / $imgmeta['image_meta']['shutter_speed']), 1) == 2.5){
                    $pshutter = "1/" . number_format((1 / $imgmeta['image_meta']['shutter_speed']), 1, '.', '') . "s";
                }
                else
                    $pshutter = "1/" . number_format((1 / $imgmeta['image_meta']['shutter_speed']), 0, '.', '') . "s";
            }
            else
                $pshutter = $imgmeta['image_meta']['shutter_speed'] . "s";
        }
        else
            $pshutter = "";
        
        if( isset($imgmeta['image_meta']['make']) )         $pmake = $imgmeta['image_meta']['make'];
        else                                                $pmake = "";
        
        if( isset($imgmeta['image_meta']['focal_length']) ) $pfocal_length = $imgmeta['image_meta']['focal_length'] . "mm";
        else                                                $pfocal_length = "";
        
        if( isset($imgmeta['image_meta']['aperture']) )     $paperature = "f/" . $imgmeta['image_meta']['aperture'];
        else                                                $paperature = "";
        
        if( isset($imgmeta['image_meta']['iso']) )          $piso = $imgmeta['image_meta']['iso'];
        else                                                $piso = "";

        // eliminate long make names like "NIKON CORPORATION"
        if( strlen($pmake)>12 && strcasecmp(substr($pmake, strlen($pmake)-12), " CORPORATION")==0 )
            $pmake = substr($pmake, 0, strlen($pmake)-12);
        
        if( strlen($pmake)==20 && strcasecmp($pmake, "PENTAX RICOH IMAGING")==0 )
            $pmake = "RICOH";
        
        // eliminate duplicate brand names in make and model field, like "Canon Canon EOS 5D"
        if( $pmake!="" &&  strcasecmp( substr($pcamera, 0, strlen($pmake)), $pmake)==0 )
            $pcamera = substr($pcamera, strlen($pmake)+1);
        
        // prevent code injections
        $pmake = htmlspecialchars($pmake, ENT_QUOTES);
        $pcamera = htmlspecialchars($pcamera, ENT_QUOTES);
        $pcamera = add_phtag_html('camera.svg', $pmake . ' ' . $pcamera);

        $pfocal_length = htmlspecialchars($pfocal_length, ENT_QUOTES);
        $pfocal_length = add_phtag_html('lense.svg', $pfocal_length);

        $paperature = htmlspecialchars($paperature, ENT_QUOTES);
        $paperature = add_phtag_html('aperture.svg', $paperature);

        $pshutter = htmlspecialchars($pshutter, ENT_QUOTES);
        $pshutter = add_phtag_html('shutter.svg', $pshutter);

        $piso = htmlspecialchars($piso, ENT_QUOTES);
        $piso = add_phtag_html('iso.svg', $piso);
        
        // ****************************************************************************************************************************
        // **
        // ** the follwing code defines the layout of the inserted line
        // **
        // ****************************************************************************************************************************
        
        // wrap image in div container
        $exifContainer = '<div class="phtags wp-element-caption">';
        $exifContainer .= $pcamera . $pfocal_length . $paperature . $pshutter . $piso;
        $exifContainer .= '</div>';

        return wrap_img_container($matches[0], $exifContainer);
        
        // ****************************************************************************************************************************
    }

    function add_phtag_html($iconfilename, $text)
    {
        return '<div class="phtag"><img src="' . plugin_dir_url( __FILE__ ) . 'assets/' . $iconfilename . '" ><span>' . $text . '</span></div>';
    }

    function wrap_img_container($content, $exifContainer)
    {
        if (preg_match('/(<img[^>]+>)/i', $content, $matches))
        {
            $imgTagText = $matches[0];
            return '<div class="exif-img-container">' . $imgTagText . $exifContainer . '</div>';
        }
        return $content;
    }
    
    //******************************************************************************************
    // search for all occurrences of "<figure...>...<img...>" and process them
    //******************************************************************************************
    function exif_figure_content($content)
    {
        return preg_replace_callback('/(<figure[^>]+>)[\s]*(<img[^>]+>)/i', 'exif_tags_images_process', $content);
    } 
    