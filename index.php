<?php

    /**
     * index.php
     *
     * Scan through all posts on a site and output them to a PDF file.
     *
     * @author     Neil Thompson <neil@spokenlikeageek.com>
     * @copyright  2023 Neil Thompson
     * @license    https://www.gnu.org/licenses/gpl-3.0.en.html  GNU General Public License v3.0
     * @link       https://github.com/williamsdb/wordpress-to-pdf
     * @see        https://www.spokenlikeageek.com/2023/08/02/exporting-all-wordpress-posts-to-pdf/ Blog post
     * 
     * ARGUMENTS
     * URL         Full web address of site to be processed (without trailing slash)
     * Username    WordPress username to use to retrieve posts
     * Password    WordPress application password
     * Output Loc  Location where you would like the output PDF file to be put
     * Sort Order  Process posts old to new (ASC) or new to old (DESC) - optional, ASC default  
     * Break point Number of posts to process before stopping - optional, leave blank for all
     *
     */

    // turn off reporting of notices
     error_reporting(E_ALL & ~E_NOTICE);
    
    // check we have what we need
    if ($argc < 5) die('Insufficient parameters passed in'.PHP_EOL);

    // load fpdf
    require('fpdf/fpdf.php');
    require('fpdfexts.php');

    // Your WordPress site URL
    $site_url = $argv[1];

    // Your WordPress username and password or application password
    $username = $argv[2];
    $password = $argv[3];

    // Order to output posts (asc or desc)
    if (isset($argv[5])){
        $order = $argv[5];
    }else{
        $order = 'asc';
    }

    // number of posts to process
    if (isset($argv[6])){
        $cnt = $argv[6];
    }else{
        $cnt = 9999999;
    }

    // API endpoint to retrieve posts
    $api_url = $site_url . '/wp-json/wp/v2/';

    // create the FPDF instance
    $pdf = new PDF();

    // save the PDF file
    date_default_timezone_set('Europe/London');
    $date = date('Y-m-d_H-i', time());
    $pdf->Open($argv[4].'/'.$date.'.pdf');

    // First page
    $pdf->AddPage();
    $pdf->SetFont('Arial','',32);
    $pdf->SetXY((($pdf->GetPageWidth())/2)-($pdf->GetStringWidth('BLOG POSTS')/2),100);
    $pdf->Cell(10,10,'BLOG POSTS');
    $pdf->SetFont('Arial','',20);
    $pdf->SetXY((($pdf->GetPageWidth())/2)-($pdf->GetStringWidth($site_url)/2),110);
    $pdf->Cell(10,10,$site_url);

    // set pagination
    $page = 1;
    $postCount = 0;
    $finished = 0;

    while ($finished==0){

        // Initialize cURL session
        $ch = curl_init($api_url.'posts?_embed&order='.$order.'&per_page=10&offset='.($page-1)*10);

        // Set cURL options for authentication
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);

        // Set cURL option to return the response instead of outputting it directly
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // if this is the first time through get the total number of posts to be processed
        if ($page == 1){
            // this function is called by curl for each header received
            curl_setopt($ch, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                return $len;
        
                $headers[strtolower(trim($header[0]))][] = trim($header[1]);
                
                return $len;
            }
            );    
        }

        // Execute the cURL session and get the API response
        $response = curl_exec($ch);
        if ($page == 1){
            $totalPages = $headers['x-wp-total'][0];
            if ($cnt == 9999999) $cnt = $totalPages-1;
        }
        $posts = json_decode($response, true);
       
        // Check for errors
        $info = curl_getinfo($ch);
        
        if ($info['http_code'] != '200') {
            die("Error retrieving posts ");
        }
        if (curl_errno($ch)) {
            die('Error: ' . curl_error($ch).PHP_EOL);
            unlink($argv[4].'/'.$date.'.pdf');
        }elseif(isset($posts['code'])){
            die($posts['message'].PHP_EOL);
            unlink($argv[4].'/'.$date.'.pdf');
        }

        // Close cURL session
        curl_close($ch);

        // Process the API response (in JSON format)
        if ($response) {
            // process this batch of posts
            $i=0;
            while ($i<count($posts)){

                // set up the output page
                $html = '';
                $pdf->AddPage("P","A4");
                $pdf->SetLeftMargin(10);

                $date = new DateTime($posts[$i]['date']);
                $formatted_date = $date->format("l, jS F Y H:i");

                // write header
                $html = '<p><b><u>'.iconv('UTF-8', 'windows-1252', html_entity_decode($posts[$i]['title']['rendered'])).'</b></u></p>';
                $pdf->SetFontSize(14);
                $pdf->WriteHTML($html);
                $html = '<p><small>'.$formatted_date.'</small></p>';
                $pdf->SetFontSize(10);
                $pdf->WriteHTML($html);

                // process featured image and write body
                if (!empty($posts[$i]['jetpack_featured_media_url'])){
                    $ret = getimagesize(strtok($posts[$i]['jetpack_featured_media_url'],'?'));
                    if ($ret !== false){
                        $width = 190;
                        $height = $ret[1]/($ret[0]/$width);
                        $pdf->Image(strtok($posts[$i]['jetpack_featured_media_url'],'?'),10,35,$width,$height,'');
                        $pdf->SetY($height+25);
                    }else{
                        $pdf->SetY(10);
                    }
                }
                $html = '<p>'.iconv('UTF-8', 'windows-1252//TRANSLIT', html_entity_decode_exclude_pre($posts[$i]['content']['rendered'])).'<p>';
                $pdf->SetFontSize(12);
                $pdf->WriteHTML($html);

                // grab any categories and tags
                $tax = $posts[$i]['_embedded']['wp:term'];
                $cat = '';
                $tag = '';
                $j = 0;
                while ($j<count($tax)){

                    $tmp = $posts[$i]['_embedded']['wp:term'][$j];

                    $k = 0;
                    while ($k<count($tmp)){

                        if ($posts[$i]['_embedded']['wp:term'][$j][$k]['taxonomy'] == 'category'){
                            if (empty($cat)){
                                $cat = $posts[$i]['_embedded']['wp:term'][$j][$k]['name'];
                            }else{
                                $cat .= ', '.$posts[$i]['_embedded']['wp:term'][$j][$k]['name'];
                            }
                        }elseif($posts[$i]['_embedded']['wp:term'][$j][$k]['taxonomy'] == 'post_tag'){
                            if (empty($tag)){
                                $tag = $posts[$i]['_embedded']['wp:term'][$j][$k]['name'];
                            }else{
                                $tag .= ', '.$posts[$i]['_embedded']['wp:term'][$j][$k]['name'];
                            }
                        }
                        $k++;
                    }

                    $j++;
                }

                // write footer
                $html = '<p>Link: <a href="'.$posts[$i]['link'].'">'.iconv('UTF-8', 'windows-1252', html_entity_decode($posts[$i]['title']['rendered'])).'</a>';
                if (!empty($cat)) $html .= '<br>Categories: '.$cat;
                if (!empty($tag)) $html .= '<br>Tags: '.$tag;
                $html .= '</p>';
                $pdf->SetFontSize(8);
                $pdf->WriteHTML($html);

                // echo out so show we are actually doing something!
                $postCount++;
                echo chr(27) . "[0G".$postCount.'/'.$totalPages." posts processed";

                $i++;
            }
        } else {
            echo 'No response from the API.';
        }

        // check to see if we have processed all posts we want
        if ($postCount>$cnt){
            $finished = 1;
        }else{
            $page++;
        }

    }

    // finalise the file
    echo PHP_EOL.'Writing output file'.PHP_EOL;
    $pdf->Output();

    function html_entity_decode_exclude_pre($html) {
        // Step 1: Extract content inside <pre> tags
        $pre_pattern = '/<pre.*?>(.*?)<\/pre>/is';
        preg_match_all($pre_pattern, $html, $pre_matches);
        
        // Step 2: Replace the <pre> content with placeholders and handle \r to \n replacement
        $placeholders = [];
        foreach ($pre_matches[0] as $index => $pre_block) {
            $placeholder = "%%%PRE_PLACEHOLDER_{$index}%%%";
            $pre_block_modified = html_entity_decode(str_replace(chr(10), "<br>", $pre_block), ENT_QUOTES | ENT_HTML401, 'UTF-8');
            $placeholders[$placeholder] = $pre_block_modified;
            $html = str_replace($pre_block, $placeholder, $html);
        }
        
        // Step 3: Decode HTML entities in the remaining content
        $html = html_entity_decode($html);
    
        // Step 4: Replace placeholders with the original (modified) <pre> content
        foreach ($placeholders as $placeholder => $pre_block) {
            $html = str_replace($placeholder, $pre_block, $html);
        }
        
        return $html;
    }

?>
