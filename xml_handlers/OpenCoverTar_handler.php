<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

require_once 'xml_handlers/abstract_handler.php';
require_once 'models/coverage.php';
require_once 'config/config.php';
require_once 'models/build.php';

class OpenCoverTarHandler extends AbstractHandler {
    protected $Build;
    protected $CoverageSummaries;

    public function __construct($buildid)    {
        parent::__construct($buildid, $buildid);
        $this->Build = new Build();
        $this->Build->Id = $buildid;
        $this->Build->FillFromId($this->Build->Id);

        $this->CoverageSummaries = array();
        $coverageSummary = new CoverageSummary();
        $coverageSummary->BuildId = $this->Build->Id;
        $this->CoverageSummaries['default'] = $coverageSummary;

        $this->Coverages = array();
        $this->CoverageFiles = array();
        $this->CoverageFileLogs = array();
    }

    public function startElement($parser, $name, $attributes) {
        parent::startElement($parser, $name, $attributes);
        /**
         *  SEQUENCEPOINT denotes a line in the source file that is exectuable and
         *  may have been executed.
         *
         *  VC -> Visit Count
         *  EL -> Line offset, reduced by one to start the file at line 0
         */
        if(($name == "SEQUENCEPOINT") && ($this->coverageFileLog)) {
            $this->coverageFileLog->AddLine($attributes['EL']-1, $attributes['VC']);
        }
    }

    // No usage of endElement
    public function endElement($parser, $name) {
    }
    /**
     *  Removes the current module, and assumes all that is left is a
     *  subdirectory and the file name, append '.cs' to get the file path
     *  in the un-tarred directory
     **/
    public function parseFullName($string) {
        foreach ($this->currentModule as $path) {
             $filePath = str_ireplace($path,'',$string);
             $filePath = str_replace('.','/',$filePath);
             if(file_exists($this->tarDir.'/'.$path.$filePath.'.cs')) {
                return $path.$filePath.'.cs';
             }
        }
        return false;
    }
    // Queries for the coverage objects for both adding source and
    // adding coverage values
    public function getCoverageObjects($path) {
        if (!array_key_exists($path, $this->CoverageFileLogs)) {
            $coverageFileLog = new CoverageFileLog();
            $coverageFileLog->BuildId =  $this->Build->Id;
            $coverageFile = new CoverageFile();
            $coverageFile->FullPath = trim($path);
            // Get the ID for this coverage file, or create a new empty one
            //if it doesn't already exist.
            $sql = pdo_query(
            "SELECT id FROM coveragefile
            WHERE fullpath='$path' AND file IS NULL");
            if (pdo_num_rows($sql) == 0) {
            pdo_query("INSERT INTO coveragefile (fullpath) VALUES ('$path')");
            $fileid = pdo_insert_id('coveragefile');
            } else {
            $coveragefile_array = pdo_fetch_array($sql);
            $fileid = $coveragefile_array['id'];
            }
            $coverageFile->Id = $fileid;

            $coverage = new Coverage();
            $coverage->CoverageFile = $coverageFile;
            $coverage->BuildId = $this->Build->Id;

            $this->Coverages[$path] = $coverage;

            $this->CoverageFiles[$path] = $coverageFile;
            $this->CoverageFileLogs[$path] = $coverageFileLog;
            $this->coverageFile =$coverageFile;
            $this->coverageFileLog = $coverageFileLog;

        } else {
            $this->coverageFile = $this->CoverageFiles[$path];
            $this->coverageFileLog = $this->CoverageFileLogs[$path];
        }

    }
    /** Text function */
    public function text($parser, $data) {
        $element = $this->getElement();
        $data = trim($data);
        // FULLNAME refers to the "namespace" of the individual file
        if ($element == 'FULLNAME' && (strlen($data))) {
            $path = $this->parseFullName($data);
            // Lookup our models & create them if they don't exist yet.
            if($path) {
              $this->getCoverageObjects($path);
            }
            else {
              $this->coverageFile = false;
              $this->coverageFileLog = False;
            }
        }
        // MODULENAME gives the folder structure that the .cs file belongs in
        if ($element == 'MODULENAME' && (strlen($data))) {
           $this->currentModule = array($data,strtolower($data));
        }
    }

    /**
     * Parse a tarball of JSON files.
    **/
    public function Parse($filename) {
        global $CDASH_BACKUP_DIRECTORY;

        // Create a new directory where we can extract our tarball.
        $dirName = $CDASH_BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME);
        mkdir($dirName);
        $this->tarDir = $dirName;
        $result = extract_tar($filename, $dirName);
        if ($result === false) {
            add_log('Could not extract ' . $filename . ' into ' . $dirName, 'OpenCoverTarHandler::Parse', LOG_ERR);
            return false;
        }

        // Now that coverageFile objects exist, add source to each .cs file
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirName),
            RecursiveIteratorIterator::CHILD_FIRST);
        $coverageSummary = $this->CoverageSummaries['default'];
        foreach ($iterator as $fileinfo) {
            $ext = substr(strstr($fileinfo->getFilename(), '.'), 1);
            if ($ext === 'cs') {
                $this->readSourceFile($this->Build->Id, $fileinfo);
            }
        }

        // Recursively search for .xml files and parse them.
        $iterator->rewind();
        foreach ($iterator as $fileinfo) {
            // need the longest extension, so getExtension() won't do here.
            $ext = substr(strstr($fileinfo->getFilename(), '.'), 1);
            if ($ext === 'xml') {
                $this->ParseOpenCoverFile($this->Build->Id, $fileinfo);
            }

        }
        // Record parsed coverage info to the database.
        foreach ($this->CoverageFileLogs as $path => $coverageFileLog) {
            $coverage = $this->Coverages[$path];
            $coverageFile = $this->CoverageFiles[$path];

            // Tally up how many lines of code were covered & uncovered.
            foreach ($coverageFileLog->Lines as $line) {
                $coverage->Covered = 1;
                if ($line == 0) {
                    $coverage->LocUntested += 1;
                } else {
                    $coverage->LocTested += 1;
                }
            }

            // Save these models to the database.
            $coverageFile->TrimLastNewline();
            $coverageFile->Update($this->Build->Id);
            $coverageFileLog->BuildId = $this->Build->Id;
            $coverageFileLog->FileId = $coverageFile->Id;
            $coverageFileLog->Insert();

            // Add this Coverage to our summary.
            $coverage->CoverageFile = $coverageFile;
            $coverageSummary->AddCoverage($coverage);
        }

        // Insert coverage summaries
        $completedSummaries = array();
        foreach ($this->CoverageSummaries as $coverageSummary) {
            if (in_array($coverageSummary->BuildId, $completedSummaries)) {
                continue;
            }

            $coverageSummary->Insert();
            $coverageSummary->ComputeDifference();

            $completedSummaries[] = $coverageSummary->BuildId;
        }

        // Delete the directory when we're done.
        DeleteDirectory($dirName);
        return true;
    }


    /**
     * Read in the source for each .cs file
     **/
    public function readSourceFile($buildid, $fileinfo) {
       // If the name starts with "TemporaryGenerated", ignore the file
       if (preg_match("/^TemporaryGenerated/",$fileinfo->getFilename())) {
           return true;
       }
       $path = $fileinfo->getPath() . DIRECTORY_SEPARATOR . $fileinfo->getFilename();
       $path = str_replace($this->tarDir.'/','',$path);
       $fileContents = file($fileinfo->getPath() . DIRECTORY_SEPARATOR . $fileinfo->getFilename());
       $this->getCoverageObjects($path);
       $inlongComment= false;
       if ($this->coverageFile) {
           foreach($fileContents as $key=>$line) {
              // Matches the beginning of a comment block
              if(preg_match("/\/[*]+/", trim($line))) {
                  $inlongComment=true;
              }

              $this->coverageFile->File .= rtrim($line).'<br>';
              if((preg_match("/^\/\//", trim($line))) or
                (preg_match("/using /", trim($line))) or
                (preg_match("/^namespace/", trim($line))) or
                (preg_match("/^public/", trim($line))) or
                (preg_match("/^protected/", trim($line))) or
                (preg_match("/^private/", trim($line))) or
                (preg_match("/^\[/", trim($line))) or
                (preg_match("/[{}]/", trim($line))) or
                ("" == trim($line)) or
                ($inlongComment)) {
                // ignore these lines
              } else {
                  // Assumed to be executable code
                  $this->coverageFileLog->AddLine($key,0);
              }
              // Captures the end of a comment block
              if(preg_match("/[*]+\//", trim($line))) {
                  $inlongComment=false;
              }
           }
       }
    }
    /**
     * Parse an individual XML file.
     **/
    public function ParseOpenCoverFile($buildid, $fileinfo) {
        // Parse this XML file.
        $parser = xml_parser_create();
        $fileContents = file_get_contents($fileinfo->getPath() . DIRECTORY_SEPARATOR . $fileinfo->getFilename());
        $parser = xml_parser_create();
        xml_set_element_handler($parser,array($this,"startElement"), array($this,'endElement'));
        xml_set_character_data_handler($parser, array($this, 'text'));
        xml_parse($parser, $fileContents, false);
    }

}
