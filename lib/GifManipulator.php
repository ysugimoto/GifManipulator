<?php

/* =============================================================
 * 
 * Multiple GIF ( also contains animated GIF ) manipulator class
 * 
 * PHP-bundled GD library cannot treats animated GIF.
 * So, we want to support animated GIF in this class.
 * 
 * This class works PHP5.1.6 and later.
 * 
 * @author Yoshiaki Sugimoto <neo.yoshiaki.sugimoto@gmail.com>
 * @created 2013-08-06
 * 
 * GIF89a spec:
 * @see http://www.w3.org/Graphics/GIF/spec-gif89a.txt
 * 
 * @usage
 * <?php
 * 
 * // Instantiate from static method
 * $gif = GifManipulator::createFromFile('/path/to/gif');
 * // Or from direct binary
 * $gif = GifManipulator::createFromBinary(file_get_contents('/path/to/gif'));
 * 
 * // Resize to 100x50px, save to /path/to/resized_gif.
 * $gif->resize(100, 50)->save('/path/to/resized.gif');
 * 
 * // Resize by ratio
 * $gif->resizeRatio(50)->save('/path/to/resized.gif); // rezied by 50%
 * 
 * // Get image resource
 * $gd = $gif->toImage();
 * 
 * // Slice image if multiple images contains
 * while ( $image = $gif->slice() ) {
 *   $image->save('/path/to/piece_gif');
 * }
 * 
 * // Or display stdout.
 * $image->display();
 * 
 * // Dump image parameters
 * $gif->dump();
 */

class GifManipulator
{
	/**
	 * Image block separator
	 * this means graphic controll extension section started
	 * (extensionIntroducer.graphicControlLabel.blockSize)
	 * @var string
	 */
	const MULTIPLE_IMAGE_SEPARATOR = "\x21\xF9\x04";
	
	/**
	 * Netscape Extension block separator
	 * this means Netscape Extension section started
	 * (ExtensionIntroducer.ExtensionLabel.blockSize)
	 * @var string
	 */
	const NETSCAPE_EXTENSION_SEPARATOR = "\x21\xFF\x0B";
	
	/**
	 * Image binary
	 * @var string
	 */
	protected $bin;
	
	/**
	 * Local image descriptor object
	 * @var stdClass
	 */
	protected $logicalDescriptor;
	
	/**
	 * Netspace Extesnion section object
	 * @var stdClass
	 */
	protected $netscapeExtension;
	
	/**
	 * Inner binary pointer
	 * @var int
	 */
	protected $pointer          = 0;
	
	/**
	 * Inner imagedesciptor pointer
	 * @var int
	 */
	protected $imagePointer     = 0;
	
	/**
	 * GIF image header string
	 * Always "GIF89a"
	 * @var string
	 */
	protected $header           = "";
	
	/**
	 * Global colors stack object
	 * @var array
	 */
	protected $globalColors     = array();
	
	/**
	 * Contains images array
	 * @var array
	 */
	protected $imageDescriptors = array();
	
	
	// ==============================================================
	
	
	/**
	 * Constructor
	 * 
	 * initial parse binary to formatted sections
	 * 
	 * @access protected
	 * @param string $binary : image binary
	 */
	protected function __construct($binary)
	{
		$this->bin = $binary;
		
		$this->parseHeader();
		$this->parseLogicalScreenDescriptor();
		$this->parseImageDescriptor();
	}
	
	
	// ==============================================================
	
	
	/**
	 * Create instance from file
	 * 
	 * @access public static
	 * @param  string $file
	 * @return GifManupulator
	 */
	public static function createFromFile($file)
	{
		$bin = file_get_contents($file);
		
		return new self($bin);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Create instance from binary string
	 * 
	 * @access public static
	 * @param  string $bin
	 * @return GifManipulator
	 */
	public static function createFromBinary($bin)
	{
		return new self($bin);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse "GIF89a" header string
	 * 
	 *     7 6 5 4 3 2 1 0   Field Name      Type
	 *    +---------------+
	 *  0 |               |  Signature       3 Bytes
	 *    +-             -+
	 *  1 |               |
	 *    +-             -+
	 *  2 |               |
	 *    +---------------+
	 *  3 |               |  Version         3 Bytes
	 *    +-             -+
	 *  4 |               |
	 *    +-             -+
	 *  5 |               |
	 *    +---------------+
	 * 
	 * @access protected
	 * @return void
	 */
	protected function parseHeader()
	{
		$this->header  = substr($this->bin, 0, 6);
		$this->pointer = 6;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse logical screen descriptor section
	 * 
	 * 
	 *     7 6 5 4 3 2 1 0   Field Name             Type
	 *    +---------------+
	 * 0  |               |  Logical Screen Width   Unsigned
	 *    +-             -+
	 * 1  |               |
	 *    +---------------+
	 * 2  |               |  Logical Screen Height  Unsigned
	 *    +-             -+
	 * 3  |               |
	 *    +---------------+
	 * 4  | |     | |     |  <Packed Fields>        See below
	 *    +---------------+
	 * 5  |               |  Background Color Index Byte
	 *    +---------------+
	 * 6  |               |  Pixel Aspect Ratio     Byte
	 *    +---------------+
	 * 
	 *  <Packed Fields>  =  Global Color Table Flag       1 Bit
	 *                      Color Resolution              3 Bits
	 *                      Sort Flag                     1 Bit
	 *                      Size of Global Color Table    3 Bits
	 * 
	 * @access protected
	 * @return void
	 */
	protected function parseLogicalScreenDescriptor()
	{
		$this->logicalDescriptor = new stdClass;
		list(, $this->logicalDescriptor->width)  = unpack("v", $this->getBytes(2));
		list(, $this->logicalDescriptor->height) = unpack("v", $this->getBytes(2));
		
		$bits = $this->getBits();
		$this->logicalDescriptor->colorTableFlag  = bindec(substr($bits, 0, 1));
		$this->logicalDescriptor->colorResolution = bindec(substr($bits, 1, 3));
		$this->logicalDescriptor->sortFlag        = bindec(substr($bits, 4, 1));
		$this->logicalDescriptor->colorTableSize  = bindec(substr($bits, 5));
		
		$this->logicalDescriptor->backgroundColorIndex = (int)ord($this->getBytes(1));
		$this->logicalDescriptor->pixelAspectRatio     = (int)ord($this->getBytes(1));
		
		if ( $this->logicalDescriptor->colorTableFlag > 0 )
		{
			$limit = 3 * pow(2, $this->logicalDescriptor->colorTableSize + 1);
			$this->globalColors = $this->parseColors($limit);
		}
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse color tables
	 * Global color table / Local color table
	 * 
	 * 
	 *       7 6 5 4 3 2 1 0   Field Name     Type
	 *      +===============+
	 *   0  |               |  Red 0          Byte
	 *      +-             -+
	 *   1  |               |  Green 0        Byte
	 *      +-             -+
	 *   2  |               |  Blue 0         Byte
	 *      +-             -+
	 *   3  |               |  Red 1          Byte
	 *      +-             -+
	 *      |               |  Green 1        Byte
	 *      +-             -+
	 *  up  |               |
	 *      +-   . . . .   -+  ...
	 *  to  |               |
	 *      +-             -+
	 *      |               |  Green 255      Byte
	 *      +-             -+
	 * 767  |               |  Blue 255       Byte
	 *      +===============+
	 * 
	 * @access protected
	 * @param  int $imit : color bit table numbers limit
	 * @return array
	 */
	protected function parseColors($limit)
	{
		$size   = 0;
		$colors = array();
		do
		{
			$color        = new stdClass;
			$color->red   = ord($this->getBytes(1));
			$color->green = ord($this->getBytes(1));
			$color->blue  = ord($this->getBytes(1));
			$colors[]     = $color;
			$size        += 3;
		}
		while ( $size < $limit );
		
		return $colors;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Check binary section is Netscape Extension
	 * 
	 * @access protected
	 * @return bool
	 */
	protected function isNetscapeExtensionStarted()
	{
		return ($this->getBytes(3, FALSE) === self::NETSCAPE_EXTENSION_SEPARATOR);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Pase netscape extension section ( for animated ) if defined
	 * 
	 * 
	 *      7 6 5 4 3 2 1 0   Field Name                  Type
	 *     +---------------+
	 *  0  |               |  Extension Introducer        Byte
	 *     +---------------+
	 *  1  |               |  Extension Label             Byte
	 *     +---------------+
	 *
	 *     +---------------+
	 *  0  |               |  Block Size                  Byte
	 *     +---------------+
	 *  1  |               |
	 *     +-             -+
	 *  2  |               |
	 *     +-             -+
	 *  3  |               |  Application Identifier      8 Bytes
	 *     +-             -+
	 *  4  |               |
	 *     +-             -+
	 *  5  |               |
	 *     +-             -+
	 *  6  |               |
	 *     +-             -+
	 *  7  |               |
	 *     +-             -+
	 *  8  |               |
	 *     +---------------+
	 *  9  |               |
	 *     +-             -+
	 * 10  |               |  Appl. Authentication Code   3 Bytes
	 *     +-             -+
	 * 11  |               |
	 *     +---------------+
	 *
	 *     +===============+
	 *     |               |
	 *     |               |  Application Data            Data Sub-blocks
	 *     |               |
	 *     |               |
	 *     +===============+
	 *
	 *     +---------------+
	 *  0  |               |  Block Terminator            Byte
	 *     +---------------+
	 * 
	 * @access protected
	 * @return void
	 */
	protected function parseNetscapeApplicationExtension()
	{
		if ( ! $this->isNetscapeExtensionStarted() )
		{
			return;
		}
		
		$this->netscapeExtension = new stdClass;
		$this->netscapeExtension->extensionIntroducer            = $this->getBytes(1);
		$this->netscapeExtension->applicationExtensionLabel      = $this->getBytes(1);
		$this->netscapeExtension->blockSize                      = $this->getBytes(1);
		$this->netscapeExtension->applicationIdentifier          = $this->getBytes(8);
		$this->netscapeExtension->appllicationAuthenticationCode = $this->getBytes(3);
		
		// Parse Netscape Extension Data Sub Blocks
		$this->netscapeExtension->dataSubBlock = array();
		
		// One time loop only?
		$this->netscapeExtension->dataSubBlock[]  = $this->parseNetscapeApplicationExtensionSubBlock();
		$this->netscapeExtension->blockTerminator = $this->getBytes(1);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse netscape extension: application data-sub-block
	 * 
	 *      7 6 5 4 3 2 1 0   Field Name        Type
	 *     +---------------+  
	 *  0  |               |  Block Size        Byte
	 *     +---------+-----+
	 *  1  |         |     |  <Packed Fields>   See below
	 *     +---------+-----+
	 *  2  |               |
	 *     +-             -+  Loop Count        Unsigned
	 *  3  |               |
	 *     +---------------+
	 * 
	 *  <Packed Fields>  =  Reserved Bit        5 Bits
	 *                      Extension Code      3 Bits
	 * 
	 * OR 
	 * 
	 *      7 6 5 4 3 2 1 0   Field Name        Type
	 *     +---------------+  
	 *  0  |               |  Block Size        Byte
	 *     +---------+-----+
	 *  1  |         |     |  <Packed Fields>   See below
	 *     +---------+-----+
	 *  2  |               |
	 *     +-             -+
	 *  3  |               |
	 *     +-             -+  Buffering Size    Unsigned long
	 *  4  |               |
	 *     +-             -+
	 *  5  |               |
	 *     +---------------+
	 * 
	 *  <Packed Fields>  =  Reserved Bit        5 Bits
	 *                      Extension Code      3 Bits
	 * 
	 * @access protected
	 * @param  string $size : size byte string
	 * @return stdClass
	 */
	protected function parseNetscapeApplicationExtensionSubBlock()
	{
		
		if ( ($size = $this->getBytes(1)) == "\x00" )
		{
			return;
		}
		$data = new stdClass;
		$data->dataSubBlockSize = $size;
		$bit                    = $this->getBits();
		$data->reserved         = bindec(substr($bit, 0, 5));
		$data->extensionCode    = bindec(substr($bit, 5));
		
		if ( $data->extensionCode == 1 )
		{
			list(, $data->loopCount) = unpack("v", $this->getBytes(2));
			$data->bufferingSize     = NULL;
		}
		else if ( $data->extensionCode == 2 )
		{
			$data->loopCount             = NULL;
			list(, $data->bufferingSize) = unpack("V", $this->getBytes(4));
		}
		else
		{
			// Future implements at 0, 3..7
		}
		
		return $data;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse Graphic control extension section
	 * 
	 *      7 6 5 4 3 2 1 0    Field Name                Type
	 *     +---------------+
	 *  0  |               |   Extension Introducer      Byte
	 *     +---------------+
	 *  1  |               |   Graphic Control Label     Byte
	 *     +---------------+
	 *
	 *     +---------------+
	 *  0  |               |   Block Size                Byte
	 *     +---------------+
	 *  1  |     |     | | |   <Packed Fields>           See below
	 *     +---------------+
	 *  2  |               |   Delay Time                Unsigned
	 *     +-             -+
	 *  3  |               |
	 *     +---------------+
	 *  4  |               |   Transparent Color Index   Byte
	 *     +---------------+
	 *
	 *     +---------------+
	 *  0  |               |   Block Terminator          Byte
	 *     +---------------+
	 *
	 *  <Packed Fields>  =     Reserved                  3 Bits
	 *                         Disposal Method           3 Bits
	 *                         User Input Flag           1 Bit
	 *                         Transparent Color Flag    1 Bit
	 * 
	 * @access protected
	 * @param  string $binary : image descriptor's section binary
	 * @return stdClass
	 */
	protected function parseGraphicControlExtension()
	{
		//$this->parseNetscapeApplicationExtension($binary);
		$gce = new stdClass;
		$gce->extensionIntroducer = $this->getBytes(1);//substr($binary, 0, 1);
		$gce->graphicControlLabel = $this->getBytes(1);//substr($binary, 1, 1);
		$gce->blockSize           = $this->getBytes(1);//substr($binary, 2, 1);
		
		$bits = $this->getBits();
		$gce->reserved         = bindec(substr($bits, 0, 3));
		$gce->disposalMethod   = bindec(substr($bits, 3, 3));
		$gce->userInputFlag    = bindec(substr($bits, 6, 1));
		$gce->transparencyFlag = bindec(substr($bits, 7));
		
		list(, $gce->delayTime) = unpack("v", $this->getBytes(2));
		$gce->transparencyIndex = ord($this->getBytes(1));
		$gce->blockTerminator   = ord($this->getBytes(1));
		
		return $gce;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Parse Image descriptor
	 * 
	 *      7 6 5 4 3 2 1 0    Field Name                  Type
	 *     +---------------+
	 *  0  |               |   Image Separator             Byte
	 *     +---------------+
	 *  1  |               |   Image Left Position         Unsigned
	 *     +-             -+
	 *  2  |               |
	 *     +---------------+
	 *  3  |               |   Image Top Position          Unsigned
	 *     +-             -+
	 *  4  |               |
	 *     +---------------+
	 *  5  |               |   Image Width                 Unsigned
	 *     +-             -+
	 *  6  |               |
	 *     +---------------+
	 *  7  |               |   Image Height                Unsigned
	 *     +-             -+
	 *  8  |               |
	 *     +---------------+
	 *  9  | | | |   |     |   <Packed Fields>             See below
	 *     +---------------+
	 *
	 *   <Packed Fields>  =    Local Color Table Flag      1 Bit
	 *                         Interlace Flag              1 Bit
	 *                         Sort Flag                   1 Bit
	 *                         Reserved                    2 Bits
	 *                         Size of Local Color Table   3 Bits
	 * 
	 * @access protected
	 * @param  string $binary
	 * @return stdClass
	 */
	protected function parseImageDescriptor()
	{
		$stackBody = '';
		$fileSize  = strlen($this->bin);
		do
		{
			$this->parseNetscapeApplicationExtension();
			$temp = $this->getBytes(3, FALSE);
			if ( $temp !== self::MULTIPLE_IMAGE_SEPARATOR )
			{
				$stackBody .= $this->getBytes(1);
				continue;
			}
			
			if ( $stackBody !== '' && count($this->imageDescriptors) > 0 )
			{
				$this->imageDescriptors[count($this->imageDescriptors) - 1]->body = rtrim($stackBody, "\x3B");
				$stackBody = "";
			}
			
			$img = new stdClass;
			$img->graphicControlExtension = $this->parseGraphicControlExtension();
			
			$this->parseNetscapeApplicationExtension();
			
			$img->body = "";
			$img->imageSeparator    = bin2hex($this->getBytes(1));
			list(, $img->imageLeftPosition) = unpack("v", $this->getBytes(2));
			list(, $img->imageTopPosition)  = unpack("v", $this->getBytes(2));
			list(, $img->imageWidth)        = unpack("v", $this->getBytes(2));
			list(, $img->imageHeight)       = unpack("v", $this->getBytes(2));
			
			$bits = $this->getBits();
			$img->localColorTable     = bindec(substr($bits, 0, 1));
			$img->interlaceFlag       = bindec(substr($bits, 1, 1));
			$img->sortFlag            = bindec(substr($bits, 2, 1));
			$img->reserved            = bindec(substr($bits, 3, 2));
			$img->localColorTableSize = bindec(substr($bits, 5));
			$img->localColors         = array();
			
			if ( $img->localColorTable > 0 )
			{
				$limit = 3 * pow(2, $img->localColorTableSize + 1);
				$size  = 0;
				do
				{
					$color              = new stdClass;
					$color->red         = ord($this->getBytes(1));
					$color->green       = ord($this->getBytes(1));
					$color->blue        = ord($this->getBytes(1));
					$img->localColors[] = $color;
					$size              += 3;
				}
				while ( $size < $limit );
			}
			
			$this->imageDescriptors[] = $img;
			
			//$img->body = rtrim(substr($binary, $pointer), "\x3b");
		}
		while ( $this->pointer < $fileSize );
		
		if ( $stackBody !== '' && count($this->imageDescriptors) > 0 )
		{
			$this->imageDescriptors[count($this->imageDescriptors) - 1]->body = rtrim($stackBody, "\x3B");
		}
	}
	
	
	// ==============================================================
	
	
	/**
	 * Get byte from binary string
	 * 
	 * @access protected
	 * @param  int $size : get byte length
	 * @param  bool $stepPointer : step pointer flag
	 * @return string $data
	 */
	protected function getBytes($size, $stepPointer = TRUE)
	{
		$bytes = substr($this->bin, $this->pointer, $size);
		if ( $stepPointer === TRUE )
		{
			$this->pointer += $size;
		}
		
		return $bytes;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Get Bits from 1 byte string
	 * 
	 * @access protected
	 * @return string $bits
	 */
	protected function getBits()
	{
		$hex  = bin2hex($this->getBytes(1));
		$bits = (string)base_convert($hex, 16, 2);
		
		return str_pad($bits, 8, "0", STR_PAD_LEFT);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Build Logical Screen Descriptor
	 * 
	 * @access protected
	 * @return string $bin : binary string
	 */
	protected function buildLogicalDescriptor()
	{
		$bin = "";
		$bin .= pack('v*', $this->logicalDescriptor->width);
		$bin .= pack('v*', $this->logicalDescriptor->height);
		
		$bits = decbin($this->logicalDescriptor->colorTableFlag)
		        . str_pad(decbin($this->logicalDescriptor->colorResolution), 3, "0", STR_PAD_LEFT)
		        . decbin($this->logicalDescriptor->sortFlag)
		        . str_pad(decbin($this->logicalDescriptor->colorTableSize), 3, "0", STR_PAD_LEFT);
		$bin .= pack("H*", str_pad(base_convert($bits, 2, 16), 2, "0", STR_PAD_LEFT));
		
		$bin .= chr($this->logicalDescriptor->backgroundColorIndex);
		$bin .= chr($this->logicalDescriptor->pixelAspectRatio);
		
		
		foreach ( $this->globalColors as $color )
		{
			$bin .= chr($color->red);
			$bin .= chr($color->green);
			$bin .= chr($color->blue);
		}
		
		return $bin;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Build Netscape Extension
	 * 
	 * @access protected
	 * @return string $bin : binary string
	 */
	protected function buildNetscapeExtension()
	{
		if ( ! $this->netscapeExtension )
		{
			return "";
		}
		
		$bin = "";
		$bin .= $this->netscapeExtension->extensionIntroducer;
		$bin .= $this->netscapeExtension->applicationExtensionLabel;
		$bin .= $this->netscapeExtension->blockSize;
		$bin .= $this->netscapeExtension->applicationIdentifier;
		$bin .= $this->netscapeExtension->appllicationAuthenticationCode;
		
		foreach ( $this->netscapeExtension->dataSubBlock as $subBlock )
		{
			$bin .= $subBlock->dataSubBlockSize;
			$bit    = str_pad(decbin($subBlock->reserved), 5, "0", STR_PAD_LEFT)
			          . str_pad(decbin($subBlock->extensionCode), 3, "0", STR_PAD_LEFT);
			$bin .= pack("H*", str_pad(base_convert($bit, 2, 16), 2, "0", STR_PAD_LEFT));
			
			if ( $subBlock->extensionCode == 1 )
			{
				$bin .= pack("v*", $subBlock->loopCount);
			}
			else if ( $subBlock->extensionCode == 2 )
			{
				$bin .= pack("V*", $subBlock->bufferingSize);
			}
		}
		
		$bin .= $this->netscapeExtension->blockTerminator;
		
		return $bin;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Build Image Descriptor and image data
	 * 
	 * @access protected
	 * @return string $bin : binary string
	 */
	protected function buildImageDescriptor($imageDescriptor)
	{
		$bin  = "";
		$bin .= $imageDescriptor->graphicControlExtension->extensionIntroducer;
		$bin .= $imageDescriptor->graphicControlExtension->graphicControlLabel;
		$bin .= $imageDescriptor->graphicControlExtension->blockSize;
		
		$bits = str_pad(decbin($imageDescriptor->graphicControlExtension->reserved), 3, "0", STR_PAD_LEFT)
		        . str_pad(decbin($imageDescriptor->graphicControlExtension->disposalMethod), 3, "0", STR_PAD_LEFT)
		        . decbin($imageDescriptor->graphicControlExtension->userInputFlag)
		        . decbin($imageDescriptor->graphicControlExtension->transparencyFlag);
		$bin .= pack("H*", str_pad((string)base_convert($bits, 2, 16), 2, "0", STR_PAD_LEFT));
		
		$bin .= pack("v*", $imageDescriptor->graphicControlExtension->delayTime);
		$bin .= chr($imageDescriptor->graphicControlExtension->transparencyIndex);
		$bin .= chr($imageDescriptor->graphicControlExtension->blockTerminator);
		
		$bin .= pack("H*", $imageDescriptor->imageSeparator);
		$bin .= pack("v*", $imageDescriptor->imageLeftPosition);
		$bin .= pack("v*", $imageDescriptor->imageTopPosition);
		$bin .= pack("v*", $imageDescriptor->imageWidth);
		$bin .= pack("v*", $imageDescriptor->imageHeight);
		
		$bits = decbin($imageDescriptor->localColorTable)
		        . decbin($imageDescriptor->interlaceFlag)
		        . decbin($imageDescriptor->sortFlag)
		        . str_pad(decbin($imageDescriptor->reserved), 2, "0", STR_PAD_LEFT)
		        . str_pad(decbin($imageDescriptor->localColorTableSize), 3, "0", STR_PAD_LEFT);
		$bin .= pack("H*", str_pad(base_convert($bits, 2, 16), 2, "0", STR_PAD_LEFT));
		
		foreach ( $imageDescriptor->localColors as $color )
		{
			$bin .= chr($color->red);
			$bin .= chr($color->green);
			$bin .= chr($color->blue);
		}
		
		$bin .= $imageDescriptor->body;
		
		return $bin;
	}
	
	
	// ==============================================================
	
	/**
	 * Slice image
	 * 
	 * @access protected
	 * @param  stdClass $imageDescriptor
	 * @return string $image : binary string
	 */
	protected function sliceImage($imageDescriptor)
	{
		$image = $this->header;
		$image .= $this->buildLogicalDescriptor();
		$image .= $this->buildNetscapeExtension();
		
		// Fit logical screen size
		if ( $imageDescriptor->imageWidth > $this->logicalDescriptor->width )
		{
			$imageDescriptor->imageWidth  = $this->logicalDescriptor->width;
		}
		if ( $imageDescriptor->imageHeight > $this->logicalDescriptor->height )
		{
			$imageDescriptor->imageHeight = $this->logicalDescriptor->height;
		}
		
		$image .= $this->buildImageDescriptor($imageDescriptor);
		$image = rtrim($image, "\x3B") . "\x3B";
		
		return $image;
	}
	
	
	// ==============================================================
	
	/**
	 * Get Image binary
	 * 
	 * @access protected
	 * @return string $image : binary string
	 */
	protected function getImageBinary()
	{
		$image  = $this->header;
		$image .= $this->buildLogicalDescriptor();
		$image .= $this->buildNetscapeExtension();
		
		foreach ( $this->imageDescriptors as $imageDescriptor )
		{
			$image .= $this->buildImageDescriptor($imageDescriptor);
		}
		
		$image = rtrim($image, "\x3B") . "\x3B";
		
		return $image;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Process resize
	 * 
	 * @access protected
	 * @param  float $rateX
	 * @param  float $rateY
	 * @return $this
	 */
	protected function execResize($rateX, $rateY)
	{
		$newImageDescriptors = array();
		
		foreach ( $this->imageDescriptors as $key => $imageDescriptor )
		{
			$gd = @imagecreatefromstring($this->sliceImage($imageDescriptor));
			if ( ! is_resource($gd) )
			{
				throw new RuntimeException('Image slice failed! This file may be invalid formatted GIF.');
			}
			
			$width  = round($imageDescriptor->imageWidth  * $rateX);
			$height = round($imageDescriptor->imageHeight * $rateY);
			$left   = round($imageDescriptor->imageLeftPosition * $rateX);
			$top    = round($imageDescriptor->imageTopPosition  * $rateY);
			$dest   = imagecreatetruecolor($width, $height);
			
			// image has transparency?
			if ( $imageDescriptor->graphicControlExtension->transparencyFlag )
			{
				// Use local color table if exists
				$colors = ( count($imageDescriptor->localColors) > 0 )
				            ? $imageDescriptor->localColors
				            : $this->globalColors;
				$color = $colors[$imageDescriptor->graphicControlExtension->transparencyIndex];
				$trans = imagecolorallocate($dest, $color->red, $color->green, $color->blue);
			}
			else
			{
				$trans = imagecolorallocate($dest, 255, 255, 255);
			}
			
			// make image from GD functions
			imagecolortransparent($dest, $trans);
			imagefill($dest, 0, 0, $trans);
			imagecopyresampled($dest, $gd, 0, 0, 0, 0, 
			                  $width, $height, $imageDescriptor->imageWidth, $imageDescriptor->imageHeight);
			
			// Get binary buffer
			ob_start();
			imagegif($dest);
			$bin = ob_get_contents();
			ob_end_clean();
			
			// And rebuild piece of image
			$gif = new GifManipulator($bin);
			$gif->swapGlobalColorsToLocalColors();
			
			// Get parsed image descriptor
			$id = end($gif->imageDescriptors);
			
			// swap graphic controller ( animate control only )
			$id->graphicControlExtension->disposalMethod = $imageDescriptor->graphicControlExtension->disposalMethod;
			$id->graphicControlExtension->userInputFlag  = $imageDescriptor->graphicControlExtension->userInputFlag;
			$id->graphicControlExtension->delayTime      = $imageDescriptor->graphicControlExtension->delayTime;
			
			// set new postion
			$id->imageTopPosition        = (int)$top;  // double to int
			$id->imageLeftPosition       = (int)$left; // double to int
			
			// release image resouces
			imagedestroy($gd);
			imagedestroy($dest);
			
			$newImageDescriptors[] = $id;
		}
		
		// Update logical screen size
		$this->imageDescriptors = $newImageDescriptors;
		
		return $this;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Swap globalColor parameters to resized image recource
	 * 
	 * @access protected
	 * @return void
	 */
	protected function swapGlobalColorsToLocalColors()
	{
		foreach ( $this->imageDescriptors as $key => $imageDescriptor )
		{
			$imageDescriptor->localColorTable     = 1;
			$imageDescriptor->localColorTableSize = $this->logicalDescriptor->colorTableSize;
			$imageDescriptor->sortFlag            = $this->logicalDescriptor->sortFlag;
			$imageDescriptor->localColors         = $this->globalColors;
			
			$this->imageDescriptors[$key] = $imageDescriptor;
		}
		
		$this->logicalDescriptor->colorTableFlag = 0;
		$this->logicalDescriptor->colorTableSize = 0;
		$this->logicalDescriptor->sortFlag       = 0;
	}
	
	
	// ==============================================================
	// public interface methods
	// ==============================================================
	
	
	/**
	 * Slice piece of image
	 */
	public function slice()
	{
		return ( isset($this->imageDescriptors[$this->imagePointer]) )
		         ? new GifManipulator($this->sliceImage($this->imageDescriptors[$this->imagePointer++]))
		         : NULL;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Returns to GD resource
	 * 
	 * @access public
	 * @return resource
	 */
	public function toImage()
	{
		return imagecreatefromstring($this->getImageBinary());
	}
	
	
	// ==============================================================
	
	
	/**
	 * Check image is animated gif
	 * 
	 * @access public
	 * @return bool
	 */
	public function isAnimated()
	{
		return ! is_null($this->netscapeExtension);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Get image size ( logical screen size )
	 * 
	 * @access public
	 * @return stdClass
	 */
	public function getSize()
	{
		$size = new stdClass;
		$size->width  = $this->logicalDescriptor->width;
		$size->height = $this->logicalDescriptor->height;
		
		return $size;
	}
	
	
	// ==============================================================
	
	
	/**
	 * Save image
	 * 
	 * @access public
	 * @param  string $savePath : save filepath
	 * @return bool : true: success, false, failed
	 * @throws RuntimeException
	 */
	public function save($savePath = "")
	{
		if ( empty($savePath) ) 
		{
			throw new RuntimeException('Save file path is required.');
		}
		
		return file_put_contents($savePath, $this->getImageBinary());
	}
	
	
	// ==============================================================
	
	/**
	 * Resize image from X-Y
	 * 
	 * @access public
	 * @param  int $x : resized width
	 * @param  int $y : resized height
	 * @return $this
	 * @throws RuntimeException
	 */	
	public function resize($x, $y)
	{
		// calculate resize rate
		$rateX = $x / $this->logicalDescriptor->width;
		$rateY = $y / $this->logicalDescriptor->height;
		
		$this->execResize($rateX, $rateY);
		
		$this->logicalDescriptor->width  = $x;
		$this->logicalDescriptor->height = $y;
		
		return $this;
	}
	
	
	// ==============================================================
	
	/**
	 * Resize image from ratio
	 * 
	 * @access public
	 * @param  int $rate : resize rate (% digit)
	 * @return $this
	 * @throws RuntimeException
	 */	
	public function resizeRatio($rate)
	{
		$per = $rate / 100;
		
		$this->execResize($per, $per);
		
		$this->logicalDescriptor->width  *= $per;
		$this->logicalDescriptor->height *= $per;
		
		return $this;
	}
	
	// ==============================================================
	
	
	/**
	 * Display Image
	 * 
	 * @access public
	 */
	public function display()
	{
		header('Content-Type: image/gif');
		echo $this->getImageBinary();
	}
	
	
	// ==============================================================
	
	
	/**
	 * Dump image data to hex/base64
	 * 
	 * @access public
	 * @return void
	 */
	public function dump()
	{
		$dump = new GifManipulator($this->bin);
		foreach ( $dump->imageDescriptors as $key => $id )
		{
			$id->body = base64_encode($id->body);
			$dump->imageDescriptors[$key] = $id;
		}
		
		$dump->bin = base64_encode($dump->bin);
		var_dump($dump);
	}
}
