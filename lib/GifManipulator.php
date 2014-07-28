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
	 * (ExtensionIntroducer.graphicControlLabel.blockSize)
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
	 * Comment Extension block separator
	 * this means Comment Extension section started
	 * (ExtensionIntroducer.CommentLabel)
	 * @var string
	 */
	const COMMENT_EXTENSION_SEPARATOR = "\x21\xFE";
	
	/**
	 * Plain Text Extension block separator
	 * this means Plain Text Extension section started
	 * (ExtensionIntroducer.PlainTextLabel)
	 * @var string
	 */
	const PLAIN_TEXT_EXTENSION_SEPARATOR = "\x21\x01\x0C";
	
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
	
	/**
	 * Embeded comment data in GIF image
	 * @var array
	 */
	protected $commentBlockData = array();
	
	/**
	 * Embeded plaing text data in GIF image
	 * @var stdClass
	 */
	protected $plainTextBlockData = array();
	
	
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
		
		$byte = ord($this->getBytes(1));
		$this->logicalDescriptor->colorTableFlag  = (( $byte & 0x80 ) >> 7);
		$this->logicalDescriptor->colorResolution = (( $byte & 0x70 ) >> 4);
		$this->logicalDescriptor->sortFlag        = (( $byte & 0x08 ) >> 3);
		$this->logicalDescriptor->colorTableSize  =  ( $byte & 0x07 );
		
		$this->logicalDescriptor->backgroundColorIndex = ord($this->getBytes(1));
		$this->logicalDescriptor->pixelAspectRatio     = ord($this->getBytes(1));
		
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
	 * Check and parse other extensions
	 *
	 * @access protected
	 * @return void
	 */
	protected function parseOtherExtensions()
	{
		$continue = FALSE;

		// Case: Netscape Extension
		if ( $this->getBytes(3, FALSE) === self::NETSCAPE_EXTENSION_SEPARATOR )
		{
			$this->parseNetScapeApplicationExtension();
			$continue = true;
		}
		// Case: Comment Extension
		else if ( $this->getBytes(2, FALSE) === self::COMMENT_EXTENSION_SEPARATOR )
		{
			$this->commentBlockData[] = $this->parseCommentExtension();
			$continue = true;
		}
		// Case: Plain Text Extension
		else if ( $this->getBytes(3, FALSE) === self::PLAIN_TEXT_EXTENSION_SEPARATOR )
		{
			$this->plainTextBlockData[] = $this->parsePlainTextExtension();
			$continue = true;
		}
		
		return $continue;
	}
	
	
	// ==============================================================
	

	/**
	 * Pase comment extension section if defined
	 * 
	 * 
	 *      7 6 5 4 3 2 1 0   Field Name                  Type
	 *     +---------------+
	 *  0  |               |  Extension Introducer        Byte
	 *     +---------------+
	 *  1  |               |  Comment Label               Byte
	 *     +---------------+
	 *
	 *     +---------------+
	 *  2  |               |  Block Size                  Byte
	 *     +===============+
	 *     |               |
	 *  N  |               |  Comment Data                Data Sub-blocks
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
	protected function parseCommentExtension()
	{
		$comment = new stdClass();
		$comment->extensionIntroducer = $this->getBytes(1);
		$comment->commentLabel        = $this->getBytes(1);
		$comment->commentSize         = ord($this->getBytes(1));
		
		$comment->commentData         = $this->getBytes($comment->commentSize);
		$comment->blockTerminator     = $this->getBytes(1);
		
		return $comment;
	}
	
	// ==============================================================
	
	
	/**
	 * Pase plain text extension section if defined
	 * 
	 * 
	 *      7 6 5 4 3 2 1 0   Field Name                  Type
	 *     +---------------+
	 *  0  |               |  Extension Introducer        Byte
	 *     +---------------+
	 *  1  |               |  Plain Text Label            Byte
	 *     +---------------+
	 *
	 *     +---------------+
	 *  0  |               |  Block Size                  Byte
	 *     +---------------+
	 *  1  |               |  Text Grid Left Position     Unsigned
	 *     +-             -+
	 *  2  |               |
	 *     +---------------+
	 *  3  |               |  Text Grid Top Position      Unsigned
	 *     +-             -+
	 *  4  |               |
	 *     +---------------+
	 *  5  |               |  Text Grid Width             Unsigned
	 *     +-             -+
	 *  6  |               |
	 *     +---------------+
	 *  7  |               |  Text Grid Height            Unsigned
	 *     +-             -+
	 *  8  |               |
	 *     +---------------+
	 *  9  |               |  Character Cell Width        Byte
	 *     +---------------+
	 * 10  |               |  Character Cell Height       Byte
	 *     +---------------+
	 * 11  |               |  Text Foreground Color Index Byte
	 *     +---------------+
	 * 12  |               |  Text background Color Index Byte
	 *     +---------------+
	 *
	 *     +===============+
	 *     |               |
	 *  N  |               |  Plain Text Data             Data Sub-blocks
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
	protected function parsePlainTextExtension()
	{
		$plainText = new stdClass;
		$plainText->extensionIntroducer  = $this->getBytes(1);
		$plainText->plainTextLabel       = $this->getBytes(1);
		$plainText->blockSize            = $this->getBytes(1);
		
		list(, $plainText->textGridLeftPosition) = unpack("v", $this->getBytes(2));
		list(, $plainText->textGridTopPosition)  = unpack("v", $this->getBytes(2));
		list(, $plainText->textGridTopWidth)     = unpack("v", $this->getBytes(2));
		list(, $plainText->textGridTopHeight)    = unpack("v", $this->getBytes(2));
		
		$plainText->characterCellWidth        = ord($this->getBytes(1));
		$plainText->characterCellHeight       = ord($this->getBytes(1));
		$plainText->textForegroundColorIndex  = ord($this->getBytes(1));
		$plainText->textBackgroundColorIndex  = ord($this->getBytes(1));
		
		$plainText->plainTextData = "";
		while ( ($byte = $this->getBytes(1)) !== "\x00" )
		{
			$plainText->plainTextData .= $byte;
		}
		$plainText->blockTerminator = $byte;
		
		return $plainText;
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
		$byte                   = ord($this->getBytes(1));
		$data->reserved         = (( $byte & 0xF8 ) >> 3);
		$data->extensionCode    =  ( $byte & 0x07 );
		
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
		$gce = new stdClass;
		$gce->extensionIntroducer = $this->getBytes(1);
		$gce->graphicControlLabel = $this->getBytes(1);
		$gce->blockSize           = $this->getBytes(1);
		
		$byte = ord($this->getBytes(1));
		$gce->reserved         = (( $byte & 0xE0 ) >> 5);
		$gce->disposalMethod   = (( $byte & 0x1C ) >> 2);
		$gce->userInputFlag    = (( $byte & 0x02 ) >> 1);
		$gce->transparencyFlag =  ( $byte & 0x01 );
		
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
			// parse other extension each section ended
			if ( $stackBody === '' )
			{
				// trick: loop call returns bool
				while ( $this->parseOtherExtensions() ) ;
			}
			
			if ( $this->getBytes(3, FALSE) !== self::MULTIPLE_IMAGE_SEPARATOR )
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
			
			while ( $this->parseOtherExtensions() ) ;
			
			$img->body                      = "";
			$img->imageSeparator            = bin2hex($this->getBytes(1));
			list(, $img->imageLeftPosition) = unpack("v", $this->getBytes(2));
			list(, $img->imageTopPosition)  = unpack("v", $this->getBytes(2));
			list(, $img->imageWidth)        = unpack("v", $this->getBytes(2));
			list(, $img->imageHeight)       = unpack("v", $this->getBytes(2));
			
			$byte = ord($this->getBytes(1));
			$img->localColorTable     = (( $byte & 0x80 ) >> 7);
			$img->interlaceFlag       = (( $byte & 0x40 ) >> 6);
			$img->sortFlag            = (( $byte & 0x20 ) >> 5);
			$img->reserved            = (( $byte & 0x18 ) >> 3);
			$img->localColorTableSize =  ( $byte & 0x07 );
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
		
		$bin .= chr(
		           ($this->logicalDescriptor->colorTableFlag  << 7) |
		           ($this->logicalDescriptor->colorResolution << 4) |
		           ($this->logicalDescriptor->sortFlag        << 3) |
		           ($this->logicalDescriptor->colorTableSize)
		        );
		
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
			$bin .= chr(
			          ($subBlock->reserved      << 3) |
			          ($subBlock->extensionCode)
			        );
			
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
		
		$bin .= chr(
		          ($imageDescriptor->graphicControlExtension->reserved         << 5) |
		          ($imageDescriptor->graphicControlExtension->disposalMethod   << 2) |
		          ($imageDescriptor->graphicControlExtension->userInputFlag    << 1) |
		          ($imageDescriptor->graphicControlExtension->transparencyFlag)
		        );
		
		$bin .= pack("v*", $imageDescriptor->graphicControlExtension->delayTime);
		$bin .= chr($imageDescriptor->graphicControlExtension->transparencyIndex);
		$bin .= chr($imageDescriptor->graphicControlExtension->blockTerminator);
		
		$bin .= pack("H*", $imageDescriptor->imageSeparator);
		$bin .= pack("v*", $imageDescriptor->imageLeftPosition);
		$bin .= pack("v*", $imageDescriptor->imageTopPosition);
		$bin .= pack("v*", $imageDescriptor->imageWidth);
		$bin .= pack("v*", $imageDescriptor->imageHeight);
		
		$bin .= chr(
		          ($imageDescriptor->localColorTable     << 7) |
		          ($imageDescriptor->interlaceFlag       << 6) |
		          ($imageDescriptor->sortFlag            << 5) |
		          ($imageDescriptor->reserved            << 3) |
		          ($imageDescriptor->localColorTableSize)
		        );
		
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
			$trans = imagecolorallocate($dest, 255, 255, 255);
			//if ( $imageDescriptor->graphicControlExtension->transparencyFlag )
			//{
			//	// Use local color table if exists
            //    $colors = ( count($imageDescriptor->localColors) > 0 )
			//	            ? $imageDescriptor->localColors
			//	            : $this->globalColors;
			//	$color = $colors[$imageDescriptor->graphicControlExtension->transparencyIndex];
			//	$trans = imagecolorallocate($dest, $color->red, $color->green, $color->blue);
			//}
			//else
			//{
			//	$trans = imagecolorallocate($dest, 255, 255, 255);
			//}
			
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
			$id->imageTopPosition  = (int)$top;  // double to int
			$id->imageLeftPosition = (int)$left; // double to int
			
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
			$id->body = strlen($id->body) . ' sized binary';
			$dump->imageDescriptors[$key] = $id;
		}
		
		$dump->bin = base64_encode($dump->bin);
		var_dump($dump);
	}
	
	
	// ==============================================================
	
	
	/**
	 * Add image layer
	 * 
	 * @access public
	 * @param  GifManipulator $image
	 * @param  int $x
	 * @param  int $y
	 * @return void
	 */
	public function addImage(GifManipulator $image, $x = 0, $y = 0)
	{
		foreach ( $image->imageDescriptors as $imageDescriptor )
		{
			if ( count($imageDescriptor->localColors) === 0 )
			{
				$imageDescriptor->localColorTable     = 1;
				$imageDescriptor->localColorTableSize = $image->logicalDescriptor->colorTableSize;
				$imageDescriptor->sortFlag            = $image->logicalDescriptor->sortFlag;
				$imageDescriptor->localColors         = $image->globalColors;
			}
			$imageDescriptor->imageLeftPosition = $x;
			$imageDescriptor->imageTopPostion   = $y;
			
			$this->imageDescriptors[] = $imageDescriptor;
		}
	}
	
	
	// ==============================================================
	
	
	/**
	 * Set animation coniguration
	 * 
	 * @access public
	 * @param  int $delayTime ( ms )
	 * @param  int $userInputFlag range(0,3)
	 * @param  bool $loop
	 * @return void
	 */
	public function setAnimation($delayTime = 1000, $userInputFlag = 0, $loop = TRUE)
	{
		foreach ( $this->imageDescriptors as $index => $imageDescriptor )
		{
			$imageDescriptor->graphicControlExtension->delayTime     = $delayTime;
			//$imageDescriptor->graphicControlExtension->userInputFlag = $userInputFlag;
			
			$this->imageDescriptors[$index] = $imageDescriptor;
		}
		
		// Image has Netscape extension?
		if ( ! $this->netscapeExtension )
		{
			$this->netscapeExtension = new stdClass;
			$this->netscapeExtension->extensionIntroducer            = "\x21";
			$this->netscapeExtension->applicationExtensionLabel      = "\xFF";
			$this->netscapeExtension->blockSize                      = "\x0B";
			$this->netscapeExtension->applicationIdentifier          = "NETSCAPE";
			$this->netscapeExtension->appllicationAuthenticationCode = "2.0";
			
			// Build Netscape Extension Data Sub Blocks
			$data = new stdClass;
			$data->dataSubBlockSize = "\x03";
			$data->reserved         = 0;
			$data->extensionCode    = 1;
			$data->loopCount        = ( $loop ) ? 0 : 1;
			$data->bufferingSize    = NULL;
			
			$this->netscapeExtension->dataSubBlock    = array($data);
			$this->netscapeExtension->blockTerminator = "\x00";
		}
		else
		{
			$data = new stdClass;
			$data->dataSubBlockSize = "\x03";
			$data->reserved         = 0;
			$data->extensionCode    = 1;
			$data->loopCount        = ( $loop ) ? 0 : 1;
			$data->bufferingSize    = NULL;
			$this->netscapeExtension->dataSubBlock = array($data);
		}
	}
	
	
	// ==============================================================
	
	
	/**
	 * Add image layer from filepath string
	 * 
	 * @access public
	 * @param  string $file
	 * @param  int $x
	 * @param  int $y
	 * @return void
	 */
	public function addImageFromFile($file, $x = 0, $y = 0)
	{
		$this->addImage(GifManipulator::createFromFile($file), $x, $y);
	}
}
