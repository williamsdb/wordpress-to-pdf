<?php

class PDF extends FPDF
{
    protected $B = 0;
    protected $I = 0;
    protected $U = 0;
    protected $HREF = '';

    protected $f;

    public function Open($file='doc.pdf')
    {
        $this->f = fopen($file,'wb');
        if(!$this->f)
            $this->Error('Unable to create output file: '.$file);
        $this->_putheader();
    }
    
    public function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
    {
        if(!isset($this->images[$file]))
        {
            // Retrieve only metadata
            $a = getimagesize($file);
            if($a===false)
                $this->Error('Missing or incorrect image file: '.$file);
            $this->images[$file] = array('w'=>$a[0], 'h'=>$a[1], 'type'=>$a[2], 'i'=>count($this->images)+1);
        }
        parent::Image($file,$x,$y,$w,$h,$type,$link);
    }
    
    public function Output($dest='', $name='', $isUTF8=false)
    {
        if($this->state<3)
            $this->Close();
    }
    
    protected function _endpage()
    {
        parent::_endpage();
        // Write page to file
        $this->_putstreamobject($this->pages[$this->page]);
        unset($this->pages[$this->page]);
    }
    
    protected function _getoffset()
    {
        return ftell($this->f);
    }
    
    protected function _put($s)
    {
        fwrite($this->f,$s."\n",strlen($s)+1);
    }
    
    protected function _putimages()
    {
        foreach(array_keys($this->images) as $file)
        {
            $type = $this->images[$file]['type'];
            if($type==1)
                $info=$this->_parsegif($file);
            elseif($type==2)
                $info=$this->_parsejpg($file);
            elseif($type==3)
                $info=$this->_parsepng($file);
            else
                $this->Error('Unsupported image type: '.$file);
            $this->_putimage($info);
            $this->images[$file]['n'] = $info['n'];
            unset($info);
        }
    }
    
    protected function _putpage($n)
    {
        $this->_newobj();
        $this->_put('<</Type /Page');
        $this->_put('/Parent 1 0 R');
        if(isset($this->PageInfo[$n]['size']))
            $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$this->PageInfo[$n]['size'][0],$this->PageInfo[$n]['size'][1]));
        if(isset($this->PageInfo[$n]['rotation']))
            $this->_put('/Rotate '.$this->PageInfo[$n]['rotation']);
        $this->_put('/Resources 2 0 R');
        if(!empty($this->PageLinks[$n]))
        {
            $s = '/Annots [';
            foreach($this->PageLinks[$n] as $pl)
                $s .= $pl[5].' 0 R ';
            $s .= ']';
            $this->_put($s);
        }
        if($this->WithAlpha)
            $this->_put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
        $this->_put('/Contents '.(2+$n).' 0 R>>');
        $this->_put('endobj');
        // Link annotations
        $this->_putlinks($n);
    }
    
    protected function _putpages()
    {
        $nb = $this->page;
        $n = $this->n;
        for($i=1;$i<=$nb;$i++)
        {
            $this->PageInfo[$i]['n'] = ++$n;
            foreach($this->PageLinks[$i] as &$pl)
                $pl[5] = ++$n;
            unset($pl);
        }
        for($i=1;$i<=$nb;$i++)
            $this->_putpage($i);
        // Pages root
        $this->_newobj(1);
        $this->_put('<</Type /Pages');
        $kids = '/Kids [';
        for($i=1;$i<=$nb;$i++)
            $kids .= $this->PageInfo[$i]['n'].' 0 R ';
        $kids .= ']';
        $this->_put($kids);
        $this->_put('/Count '.$nb);
        if($this->DefOrientation=='P')
        {
            $w = $this->DefPageSize[0];
            $h = $this->DefPageSize[1];
        }
        else
        {
            $w = $this->DefPageSize[1];
            $h = $this->DefPageSize[0];
        }
        $this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$w*$this->k,$h*$this->k));
        $this->_put('>>');
        $this->_put('endobj');
    }
    
    protected function _putheader()
    {
        if($this->_getoffset()==0)
            parent::_putheader();
    }
    
    protected function _enddoc()
    {
        parent::_enddoc();
        fclose($this->f);
    }

    function WriteHTML($html)
    {
        // HTML parser
        $html = str_replace("\n",' ',$html);
        $a = preg_split('/<(.*)>/U',$html,-1,PREG_SPLIT_DELIM_CAPTURE);
        foreach($a as $i=>$e)
        {
            if($i%2==0)
            {
                // Text
                if($this->HREF)
                    $this->PutLink($this->HREF,$e);
                else
                    $this->Write(5,$e);
            }
            else
            {
                // Tag
                if($e[0]=='/')
                    $this->CloseTag(strtoupper(substr($e,1)));
                else
                {
                    // Extract attributes
                    $a2 = explode(' ',$e);
                    $tag = strtoupper(array_shift($a2));
                    $attr = array();
                    foreach($a2 as $v)
                    {
                        if(preg_match('/([^=]*)=["\']?([^"\']*)/',$v,$a3))
                            $attr[strtoupper($a3[1])] = $a3[2];
                    }
                    $this->OpenTag($tag,$attr);
                }
            }
        }
    }

    function OpenTag($tag, $attr)
    {
        // Opening tag
        if($tag=='B' || $tag=='I' || $tag=='U')
            $this->SetStyle($tag,true);
        if($tag=='A')
            $this->HREF = $attr['HREF'];
        if($tag=='BR')
            $this->Ln(5);
        if($tag=='P')
            $this->Ln(8);
    }

    function CloseTag($tag)
    {
        // Closing tag
        if($tag=='B' || $tag=='I' || $tag=='U')
            $this->SetStyle($tag,false);
        if($tag=='A')
            $this->HREF = '';
    }

    function SetStyle($tag, $enable)
    {
        // Modify style and select corresponding font
        $this->$tag += ($enable ? 1 : -1);
        $style = '';
        foreach(array('B', 'I', 'U') as $s)
        {
            if($this->$s>0)
                $style .= $s;
        }
        $this->SetFont('',$style);
    }

    function PutLink($URL, $txt)
    {
        // Put a hyperlink
        $this->SetTextColor(0,0,255);
        $this->SetStyle('U',true);
        $this->Write(5,$txt,$URL);
        $this->SetStyle('U',false);
        $this->SetTextColor(0);
    }

    function Footer()
    {
        // Go to 1.5 cm from bottom
        $this->SetY(-15);
        // Select Arial italic 8
        $this->SetFont('Arial','I',8);
        // Print centered page number
        if ($this->PageNo() != 1){
            $this->Cell(0,10,'Page '.$this->PageNo(),0,0,'C');
        }else{
            $this->Cell(0,10,'Printed '.date('l, dS F Y', time()),0,0,'C');
        }
    }
}

?>