<?php

    // check we have what we need
    if ($argc != 5) die('Insufficient parameters passed in'.PHP_EOL);

    // load fpdf
    require('fpdf/fpdf.php');
    require('fpdfexts.php');

    // Your WordPress site URL
    $site_url = $argv[1];

    // Your WordPress username and password or application password
    $username = $argv[2];
    $password = $argv[3];

    // Order to output posts (asc or desc)
    $order = 'asc';

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
    $finished = 0;

    while ($finished==0){

        // Initialize cURL session
        $ch = curl_init($api_url.'posts?_embed&order='.$order.'&per_page=10&offset='.($page-1)*10);

        // Set cURL options for authentication
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);

        // Set cURL option to return the response instead of outputting it directly
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the cURL session and get the API response
        $response = curl_exec($ch);
        $posts = json_decode($response, true);

        // Check for errors
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
                    $ret = getimagesize($posts[$i]['jetpack_featured_media_url']);
                    if ($ret !== false){
                        $width = 190;
                        $height = $ret[1]/($ret[0]/$width);
                        $pdf->Image(strtok($posts[$i]['jetpack_featured_media_url'],'?'),10,35,$width,$height,'');
                        $pdf->SetY($height+25);
                    }else{
                        $pdf->SetY(10);
                    }
                }
                $html = '<p>'.iconv('UTF-8', 'windows-1252', html_entity_decode($posts[$i]['content']['rendered'])).'<p>';
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
                echo '.';

                $i++;
            }
        } else {
            echo 'No response from the API.';
        }


        if (count($posts)<10){
            $finished = 1;
        }else{
            $page++;
        }

    }

    // finalise the file
    echo PHP_EOL.'Writing output file'.PHP_EOL;
    $pdf->Output();

?>
