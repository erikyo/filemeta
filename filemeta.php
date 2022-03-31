<?php

namespace filemeta;

require_once './globs.php';

/**
 * a Library to extract any kind of metadata stored inside files
 */
class Filemeta
{
    protected $file_path;
    protected $file_content;
    protected $metadata;

    /**
     * Extract any kind of metadata stored inside files,
     * like Exif, ICCP and XMP
     *
     * @param string $path
     */
    public function __construct($path)
    {
        $this->file_path    = $path;
        $this->file_content = $this->getFile($path);
        $this->metadata     = [];
    }

    /**
     * Get the file content
     *
     * @param string $file
     *
     * @return false|resource
     */
    private function getFile($file)
    {
        return fopen($file, 'rb');
    }


    /**
     * @param $file_header
     *
     * @return mixed|null
     */
    private function getSignature($file_header)
    {
        return self::readXHex($file_header, 4); // read 32 bits - 4 char | riff header
    }

    /**
     * @param $string
     * @param $length
     *
     * @return mixed|null
     */
    private function readXHex($string, $length)
    {
        return self::readUnpack($string, 'H*', $length);
    }

    /**
     * @param $string
     * @param $type
     * @param $length
     *
     * @return mixed|null
     */
    private function readUnpack($string, $type, $length)
    {
        $data = unpack($type, fread($string, $length));

        return array_pop($data);
    }

    /**
     * Detect filetype by signature
     * https://en.wikipedia.org/wiki/List_of_file_signatures
     *
     * @param $signature
     *
     * @return string
     * @throws Exception
     */
    private function getFiletype($signature)
    {
        if (strpos($signature, "<?ph")) {
            throw new \Exception("signature contains php code", 500);
        }

        if (strpos($signature, MAGIC_NUMBERS["RIFF"]) === 0) {
            // read 32 bits (uint32) | the whole file size in bytes
            $this->metadata['filesize']           = self::readInt($this->file_content);
            $this->metadata['readeable-filesize'] = self::formatBytes($this->metadata['filesize']);

            // read 32 bits - 4 char | webp extension
            $this->metadata['extension'] = self::readXChar($this->file_content, 4);

            return "RIFF";
        }

        // compare the file magic numbers with the library available formats
        foreach (MAGIC_NUMBERS as $ext => $magic) {
            if (strpos($signature, $magic) === 0) {
                return $ext;
            }
        }

        return "you are looking for signature that start with '$signature' or this mime is unknown";
    }

    /**
     * @param $string
     *
     * @return mixed|null
     */
    private function readInt($string)
    {
        return self::readUnpack($string, 'I', 4);
    }

    /**
     * a function to convert a file size in bytes to a human-readable file size:
     *
     * @param $size
     * @param $precision
     *
     * @return string
     */
    private function formatBytes($size, $precision = 2)
    {
        $base     = log($size, 1024);
        $suffixes = [ '', 'K', 'M', 'G', 'T' ];

        return round(pow(1024, $base - floor($base)), $precision) . $suffixes[ floor($base) ];
    }

    /**
     * @param $string
     * @param $length
     *
     * @return mixed|null
     */
    private function readXChar($string, $length)
    {
        return self::readUnpack($string, 'a*', $length);
    }

    /**
     * @param int $maxChunks
     *
     * @return bool
     */
    private function getRiffChunks($maxChunks = - 1)
    {
        $numberOfChunks = 0;

        // Find out the chunks
        while (! feof($this->file_content) && ! ($numberOfChunks >= $maxChunks && $maxChunks >= 0)) {
            $chunkStart = ftell($this->file_content);

            $chunkFourCC = fread($this->file_content, 4);

            $chunkSize = fread($this->file_content, 4);

            if (! $chunkFourCC || ! $chunkSize || strlen($chunkSize) != 4) {
                break;
            }

            $intChunkSize = self::toUInt32($chunkSize);

            // Add chunk info to the info structure
            $this->metadata['riffs-chunks'][ $chunkFourCC ] = [
                'start' => $chunkStart,
                'size'  => $intChunkSize
            ];

            // Uneven chunks have padding bytes
            $padding = $intChunkSize % 2;

            // Seek to the next chunk
            fseek($this->file_content, $intChunkSize + $padding, SEEK_CUR);
        }

        return ! empty($this->metadata['riffs-chunks']) ? true : false;
    }

    /**
     *
     * UTILS: this is a set of utility to avoid repetitions
     * generally speaking they do what their title says
     * for example: readInt returns an integer number from the string
     *
     *
     * @param $string
     *
     * @return mixed
     */
    private function toUInt32($string)
    {
        return unpack('V', $string)[1];
    }

    private function decodeLossyChunkHeader($header)
    {
        // Bytes 0-3 are 'VP8 '
        // Bytes 4-7 are the VP8 stream size
        // Bytes 8-10 are the frame tag
        // Bytes 11-13 are 0x9D 0x01 0x2A called the sync code
        $syncCode = substr($header, 11, 3);
        if ($syncCode != "\x9D\x01\x2A") {
            $this->metadata['errors']['VP8 '] = 'WebP decodeLossyChunkHeader Invalid sync code: ' . bin2hex($syncCode);
        }

        // Bytes 14-17 are image size
        $imageSize = unpack('v2', substr($header, 14, 4));
        // Image sizes are 14 bit, 2 MSB are scaling parameters which are ignored here
        $this->metadata += [
            'compression' => 'lossy',
            'width'       => $imageSize[1] & 0x3FFF,
            'height'      => $imageSize[2] & 0x3FFF
        ];
    }

    private function decodeLosslessChunkHeader($header)
    {
        // Bytes 0-3 are 'VP8L'
        // Bytes 4-7 are chunk stream size
        // Byte 8 is 0x2F called the signature
        if ($header[8] != "\x2F") {
            $this->metadata['errors']['VP8L'] = 'Invalid signature: ' . bin2hex($header[8]);
        }

        // Bytes 9-12 contain the image size
        // Bits 0-13 are width-1; bits 15-27 are height-1
        $imageSize = unpack('C4', substr($header, 9, 4));

        $this->metadata += [
            'compression' => 'lossless',
            'width'       => ($imageSize[1] | (($imageSize[2] & 0x3F) << 8)) + 1,
            'height'      => ((($imageSize[2] & 0xC0) >> 6) | ($imageSize[3] << 2) | (($imageSize[4] & 0x03) << 10)) + 1
        ];
    }

    private function decodeExtendedChunkHeader($header)
    {
        // Bytes 0-3 are 'VP8X'
        // Byte 4-7 are chunk length
        // Byte 8-11 are a flag bytes
        $flags = unpack('c', substr($header, 8, 1));

        // Byte 12-17 are image size (24 bits)
        $width  = unpack('V', substr($header, 12, 3) . "\x00");
        $height = unpack('V', substr($header, 15, 3) . "\x00");

        $this->metadata += [
            'compression'  => 'unknown',
            'animated'     => ($flags[1] & VP8X_ANIM) == VP8X_ANIM,
            'transparency' => ($flags[1] & VP8X_ALPHA) == VP8X_ALPHA,
            'EXIF'         => ($flags[1] & VP8X_EXIF) == VP8X_EXIF,
            'ICC'          => ($flags[1] & VP8X_ICC) == VP8X_ICC,
            'XMP'          => ($flags[1] & VP8X_XMP) == VP8X_XMP,
            'width'        => ($width[1] & 0xFFFFFF) + 1,
            'height'       => ($height[1] & 0xFFFFFF) + 1
        ];
    }

    private function decodeExifChunkHeader($img_metadata)
    {
        // EXIF
        // TODO: here the first bug! sometimes the exif header is jfif like and needs to be parsed in the "old" fashioned way (TLDR. it's shifted of 4byte)
        $header_format = 'A4type/' . // get 4 char
                         'I1size/';  // get 1 int

        $header = unpack($header_format, substr($img_metadata, 0, 8));

        // fetch header in order to find "0000008" that marks the beginning of exif data before the idf count (what we need)
        $meta_chunk = unpack('H40', substr($img_metadata, 8, 20))[1];

        $exif_start       = strpos($meta_chunk, "00000008");
        $exif_start_shift = ($exif_start === 8) ? 8 : 8 + (($exif_start - 8) * .5);

        $header_riff_format = 'A2byte_order/' . // 2byte get 4 string |  "II" (4949.H) (little endian) or "MM" (4D4D.H) (big endian)
                              'H4fixed42/' . // 2byte get 4 string | magic number 42 fixed 002A.h
                              'H8offset/' .  // 4byte get 4 string | 0th IFD offset. If the TIFF header is followed immediately by the 0th IFD, it is written as 00000008.H.
                              'H*idf_count/';  // the count of identifiers to read in the next function

        $metadata = array_merge($header, unpack($header_riff_format, substr($img_metadata, $exif_start_shift, 10)), [ 'orientation' => '' ]);

        // TODO: has to be checked if correct... the reason is that value needs to be decoded from hex with some rules
        // (following the description of the field)
        // The number of values. It should be noted carefully that the count is not the sum of the bytes. In the case of one
        // value of SHORT (16 bits), for example, the count is '1' even though it is 2 bytes.
        $metadata['idf_count'] = hexdec($metadata['idf_count']);

        for ($i = 0; $i <= $metadata['idf_count'] - 1; $i++) {
            // Read the next 12 bytes each loop
            $exif_raw = substr($img_metadata, 10 + $exif_start_shift + (12 * $i), 12);

            // Unpack 12bytes as 24hex values into char string
            $meta_chunk = unpack('H24', $exif_raw)[1];

            // Split the hex string into
            $meta_chunk_tag    = substr($meta_chunk, 0, 4);
            $meta_chunk_offset = hexdec(substr($meta_chunk, 20, 8));
            $meta_chunk_count  = hexdec(substr($meta_chunk, 8, 8)); // the number of values (string length)

            // TODO: If the value is smaller than 4 bytes,
            // the value is stored in the 4-byte area starting from the left,
            // i.e., from the lower end of the byte offset area
            if ($meta_chunk_tag == '0112') {
                $this->metadata['orientation'] = substr($meta_chunk, 16, 4);
            }


            $segment_type = substr($meta_chunk, 4, 4);
            $segment_value = substr($img_metadata, $meta_chunk_offset, $meta_chunk_count);

            // saves the hex decoded data
            $metadata[ $i ] = [
                'tag'            => $meta_chunk_tag,
                'type'           => $segment_type, // 2bit TYPE: 0-1 Tag | 2-3 type | 4-7 Count | 8-11 value offset
                'value'          => $segment_type !== 'APP1' ? $segment_value : self::decodeExifChunkHeader($segment_value), // 4bit the item value - 1 byte 8bit uint | 2 ascii 8byte with 7bit ascii code | 3 short 16bit uint | 4 long 32bit uint | 5 rational long/long | 7 undefined 8bit any | 9 SLONG 4byte singed int | 10 SRATIONAL SLONG/SLONG
                'raw_value_data' => [ 'hex' => $meta_chunk, 'offset' => $meta_chunk_offset, 'count' => $meta_chunk_count ],
            ];
        }

        $this->metadata['EXIF'] = $metadata;
    }

    /**
     * @param $img_metadata
     *
     * @return void
     */
    private function decodeIccpChunkHeader($img_metadata)
    {

        // ITPC PARSE
        // https://metacpan.org/dist/Image-ExifTool/view/lib/Image/ExifTool/TagNames.pod#ICC_Profile-Tags
        // https://www.color.org/icc_specs2.xalter
        // https://www.color.org/specification/ICCSpecRevision_25-02-10_dictType.pdf
        // https://www.color.org/icc32.pdf (definitions near page 80)
        $header_format = 'A4type/' . // get 4 string
                         'I1size/'; // get 1byte integer

        $metadata['parsed']['header'] = unpack($header_format, substr($img_metadata, 0, 8));

        $metadata['parsed']['raw-header'] = $raw_header = substr($img_metadata, 8, 128);
        $metadata['parsed']['raw-body']   = $raw_body = substr($img_metadata, 128);

        $iccp_format = 'Z4tag/' . 'Noffset/' . 'Nlength/';

        for ($i = 1; $i <= 10; $i++) {
            $parsed_iccp[ $i ] = unpack($iccp_format, substr($raw_body, 12 * $i, 12));

            $parsed_iccp[ $i ]['data'] = substr($img_metadata, $parsed_iccp[ $i ]['offset'] + 8, $parsed_iccp[ $i ]['length']);

            $metadata["body-$i"] = [
                "tag"  => substr($parsed_iccp[ $i ]['tag'], 0, 4),
                "type" => substr($parsed_iccp[ $i ]['data'], 0, 4),
                "data" => substr($parsed_iccp[ $i ]['data'], 4)
            ];
        }

        $metadata['icc'] = "ICC profile present";


        if (substr($raw_header, 36, 4) != 'acsp') {
            // check for icc profile
            $metadata['icc'] = "ICC profile INVALID (no acsp flag) " . substr($raw_header, 32, 4);
        } else {
            // invalid ICC profile
            $input                  = substr($raw_header, 16, 4);
            $output                 = substr($raw_header, 20, 4);
            $metadata['icc-input']  = 'ICC profile Input: ' . $input;
            $metadata['icc-output'] = 'ICC profile Output: ' . $output;

            // Ignore Color profiles for conversion to other color-spaces e.g. CMYK/Lab
            if ($input != 'RGB ' || $output != 'XYZ ') {
                $metadata['icc'] = 'ICC profile ignored';
            }
        }

        $this->metadata['ICCP'] = $metadata;
    }

    /**
     * @param $img_metadata
     *
     * @return void
     */
    private function decodeXmpChunkHeader($img_metadata)
    {

        // XMP PROFILE
        // https://en.wikipedia.org/wiki/Extensible_Metadata_Platform
        // https://web.archive.org/web/20180919181934/http://www.metadataworkinggroup.org/pdf/mwg_guidance.pdf
        // https://github.com/jeroendesloovere/xmp-metadata-extractor/blob/master/src/XmpMetadataExtractor.php

        $header_format = 'A4type/' . // get 4 string
                         'Vsize/'; // get 4 string

        $this->metadata['XMP']['parsed'] = unpack($header_format, substr($img_metadata, 0, 8));

        $this->metadata['XMP']["raw"] = $xmp_raw = utf8_encode(htmlspecialchars(substr($img_metadata, 8)));
    }

    /**
     * @param $maxChunks
     *
     * @return void
     */
    private function getJFIFchunks()
    {

        // echo $this->meta['type'] = self::readXChar($this->file_content, 16); // read 4byte - 4 char | substring this to find jP or jP2 or JFIF type

        fseek($this->file_content, 20);

        $data = fread($this->file_content, 2);

        $hit_compressed_image_data = false;

        while (($data[1] != "\xD9") && (! $hit_compressed_image_data) && (! feof($this->file_content))) {
            // Found a segment to look at.
            // Check that the segment marker is not a Restart marker - restart markers don't have size or data after them
            if ((ord($data[1]) < 0xD0) || (ord($data[1]) > 0xD7)) {
                // Segment isn't a Restart marker
                // Read the next two bytes (size)
                $sizestr = fread($this->file_content, 2);

                // convert the size bytes to an integer
                $decodedsize = unpack("nsize", $sizestr);

                // Save the start position of the data
                $segdatastart = ftell($this->file_content);

                // Read the segment data with length indicated by the previously read size
                $segdata = fread($this->file_content, $decodedsize['size'] - 2);

                // Store the segment information in the output array
                $this->metadata['jfif'][ $segdatastart ] = [
                  "SegType"      => ord($data[1]),
                  "SegName"      => FILEMETA_INDEXES["JPEG_Segment_Names"][ ord($data[1]) ],
                  "SegDesc"      => FILEMETA_INDEXES["JPEG_Segment_Descriptions"][ ord($data[1]) ],
                  "SegDataStart" => $segdatastart,
                  "SegData"      => serialize($segdata)
                ];
            }

            // If this is a SOS (Start Of Scan) segment, then there is no more header data - the compressed image data follows
            if ($data[1] == "\xDA") {
                // Flag that we have hit the compressed image data - exit loop as no more headers available.
                $hit_compressed_image_data = true;
            } else {
                // Not an SOS - Read the next two bytes - should be the segment marker for the next segment
                $data = fread($this->file_content, 2);

                // Check that the first byte of the two is 0xFF as it should be for a marker
                if ($data[0] != "\xFF") {
                    // NO FF found - close file and return - JPEG is probably corrupted
                    fclose($this->file_content);
                }
            }
        }
    }




    /**
     * @param bool|array $type | if bool - true returns all the metadata, false returns filetype and basic meta (depends on filetype)
     *                           if array will return the specified file metadata (if they are found)
     *
     * @return string|array value | the parsed meta
     */
    public function extractMeta($type = true)
    {

      // get the first 4 byte from file
        $magic_numbers = self::getSignature($this->file_content);

        // return if the file isn't found or completely empty
        if (! $magic_numbers) {
            return false;
        }

        // detect the filetype given the file header
        $magic = self::getFiletype($magic_numbers);

        // store some useful file info
        $this->metadata['filename'] = $this->file_path;
        $this->metadata['ext']      = pathinfo($this->file_path, PATHINFO_EXTENSION);

        // TODO: each file has it own header with different size in bytes so this will be moved in "get_filetype"
        // that will return the basic information stored in the first bytes of the files
        if ($magic == 'RIFF') {
            if (self::getRIFFchunks()) {
                foreach ($this->metadata['riffs-chunks'] as $chunk_name => $chunk_data) {
                    $chunkHeader = file_get_contents($this->file_path, false, null, $chunk_data['start'], in_array($chunk_name, [ 'VP8 ', 'VP8L', 'VP8X' ]) ? MINIMUM_CHUNK_HEADER_LENGTH : $chunk_data['size'] + 8);

                    switch ($chunk_name) {
                        case 'VP8 ':
                              self::decodeLossyChunkHeader($chunkHeader);
                            break;

                        case 'VP8L':
                            self::decodeLosslessChunkHeader($chunkHeader);
                            break;

                        case 'VP8X':
                            self::decodeExtendedChunkHeader($chunkHeader);
                            break;

                        case 'EXIF':
                                  self::decodeExifChunkHeader($chunkHeader);
                            break;

                        case 'ICCP':
                                self::decodeIccpChunkHeader($chunkHeader);
                            break;

                        case 'XMP ':
                              self::decodeXmpChunkHeader($chunkHeader);
                            break;

                        default:
                            echo $chunk_name . " ";
                    }
                }
            }
        } elseif ($magic == 'jpeg') {
            $this->getJFIFchunks();

            if (! empty($this->metadata)) {
                echo "unknown filetype ($magic)";
            }
        } else {
            fseek($this->file_content, 0);

            for ($i = 0; $i < 10; $i++) {
                if (! feof($this->file_content)) {
                    break;
                }
                $hex = bin2hex(fread($this->file_content, 4));
                print $hex . "\n | " . dechex($hex);
            }
        }

        return $this->metadata;
    }
}
