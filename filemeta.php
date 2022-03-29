<?php

include_once 'globs.php';

class Filemeta {

    protected $file_path;
    protected $file_content;
    protected $meta;

    /**
     * Extract any kind of metadata stored inside files,
     * like Exif, ICCP and XMP
     *
     * @param string $filepath
     */
    public function __construct( $filepath ) {
        $this->file_path    = $filepath;
        $this->file_content = $this->get_file($filepath);
        $this->meta = array();
    }

    /**
     * UTILS
     **/
    private function readUInt32( $string ) {
        return unpack( 'V', $string )[1];
    }

    private function readInt($string){
        return self::readUnpack($string, 'I', 4);
    }

    private function readXHex($string, $length){
        return self::readUnpack($string, 'H*', $length);
    }

    private function readXChar($string, $length){
        return self::readUnpack($string, 'a*', $length);
    }

    private function readUnpack($string, $type, $length){
        $data = unpack($type, fread($string, $length));
        return array_pop($data);
    }

    private function formatBytes($size, $precision = 2) {
        $base = log($size, 1024);
        $suffixes = array('', 'K', 'M', 'G', 'T');

        return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
    }


    /**
     * Get the file content
     *
     * @param string $file
     *
     * @return false|resource
     */
    private function get_file($file) {
        return fopen($file, 'rb');
    }

    /**
     * @param $file_header
     *
     * @return mixed|null
     */
    private function get_signature($file_header) {
        return self::readXHex($file_header, 4); // read 32 bits - 4 char | riff header
    }

    /**
     * Detect filetype by signature
     * https://en.wikipedia.org/wiki/List_of_file_signatures
     * @param $signature
     *
     * @return string  extension
     */
    private function get_filetype($signature) {

        if (strpos( $signature, MAGIC_NUMBERS["RIFF"] ) === 0) {

            // read 32 bits (uint32) | the whole file size in bytes
            $this->meta['filesize'] = self::readInt($this->file_content);
            $this->meta['readeable-filesize'] = self::formatBytes($this->meta['filesize']);

            // read 32 bits - 4 char | webp extension
            $this->meta['extension'] = self::readXChar($this->file_content, 4);

            return "RIFF";
        }

        // compare the file magic numbers with the library available formats
        foreach(MAGIC_NUMBERS as $ext => $magic) {
            if (strpos($signature, $magic) === 0) return $ext;
        }

        return "you are looking for signature that start with '$signature' or this mime is unknown";
    }

    /**
     * @param int $maxChunks
     *
     * @return array|false|bool
     */
    protected function get_riff_chunks( int $maxChunks = -1 ) {

        $numberOfChunks = 0;

        // Find out the chunks
        while ( ! feof( $this->file_content ) && ! ( $numberOfChunks >= $maxChunks && $maxChunks >= 0 ) ) {

            $chunkStart = ftell( $this->file_content );

            $chunkFourCC = fread( $this->file_content, 4 );

            $chunkSize = fread( $this->file_content, 4 );

            if ( ! $chunkFourCC || ! $chunkSize || strlen( $chunkSize ) != 4 ) return false;

            $intChunkSize = self::readUInt32( $chunkSize );

            // Add chunk info to the info structure
            $this->meta[] = array(
                'fourCC' => $chunkFourCC,
                'start'  => $chunkStart,
                'size'   => $intChunkSize
            );

            // Uneven chunks have padding bytes
            $padding = $intChunkSize % 2;

            // Seek to the next chunk
            fseek( $this->file_content, $intChunkSize + $padding, SEEK_CUR );
        }
    }

    /**
     * @param $maxChunks
     *
     * @return array|false|bool
     */
    protected function get_jfif_chunks( ) {

        // echo $this->meta['type'] = self::readXChar($this->file_content, 16); // read 4byte - 4 char | substring this to find jP or jP2 or JFIF type

        fseek($this->file_content, 20);

        $data = fread($this->file_content, 2);

        $hit_compressed_image_data = false;

        while ( ( $data[1] != "\xD9" ) && ( ! $hit_compressed_image_data ) && ( ! feof( $this->file_content ) ) ) {
            // Found a segment to look at.
            // Check that the segment marker is not a Restart marker - restart markers don't have size or data after them
            if ( ( ord( $data[1] ) < 0xD0 ) || ( ord( $data[1] ) > 0xD7 ) ) {
                // Segment isn't a Restart marker
                // Read the next two bytes (size)
                $sizestr = fread( $this->file_content, 2 );

                // convert the size bytes to an integer
                $decodedsize = unpack ("nsize", $sizestr);

                // Save the start position of the data
                $segdatastart = ftell( $this->file_content );

                // Read the segment data with length indicated by the previously read size
                $segdata = fread( $this->file_content, $decodedsize['size'] - 2 );

                // Store the segment information in the output array
                $this->meta['jfif'][$sizestr] = array(
                    "SegType"      => ord( $data[1] ),
                    "SegName"      => FILEMETA_INDEXES["JPEG_Segment_Names"][ ord( $data[1] ) ],
                    "SegDesc"      => FILEMETA_INDEXES["JPEG_Segment_Descriptions"][ ord( $data[1] ) ],
                    "SegDataStart" => $segdatastart,
                    "SegData"      => serialize($segdata)
                );
            }

            // If this is a SOS (Start Of Scan) segment, then there is no more header data - the compressed image data follows
            if ( $data[1] == "\xDA" ) {
                // Flag that we have hit the compressed image data - exit loop as no more headers available.
                $hit_compressed_image_data = true;
            } else {
                // Not an SOS - Read the next two bytes - should be the segment marker for the next segment
                $data = fread( $this->file_content, 2 );

                // Check that the first byte of the two is 0xFF as it should be for a marker
                if ( $data[0] != "\xFF" ) {
                    // NO FF found - close file and return - JPEG is probably corrupted
                    fclose( $this->file_content );

                    return false;
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
    public function extract_meta($type = true) {

        // get the first 4 byte from file
        $magic_numbers = self::get_signature($this->file_content);

        // return if the file isn't found or completely empty
        if (!$magic_numbers) return false;

        // detect the filetype given the file header
        $magic = self::get_filetype($magic_numbers);

        $this->meta['filename'] = $this->file_path; // read 32 bits (uint32) | the whole file size
        $this->meta['ext'] = pathinfo($this->file_path, PATHINFO_EXTENSION);

        // TODO: each file has it own header with different size in bytes so this will be moved in "get_filetype" that will return the basic information stored in the first bytes of the files
        if ($magic == 'RIFF') {

            self::get_riff_chunks();

        } else if ($magic == 'jpeg') {

            self::get_jfif_chunks();

            if (!empty($this->meta)) echo "unknown filetype ($magic)";
        }

        return $this->meta;
    }
}
