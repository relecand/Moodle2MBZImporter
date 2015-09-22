<?php

define('CLI_SCRIPT', TRUE);

//MBZ folder with mbz files
$path_to_mbz="/moodle/restore/test";
//temp/backup/import -> has to be there
$extract_path="/moodle/moodledata/temp/backup/import";
//Course category, where the courses are restored
$categoryid=15;
//Admin-User ID
$admin_user_id=2;
//Local Log file
$log="/moodle/Moodle2MBZImporter/mbz_import_error.log";

require_once("/moodle/moodle/config.php");
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class Logging
{
    public static function writeLog($msg,Exception $e,$log,$file) {
        error_log(date('Y-m-d H:i:s').":".$file."---->".$msg.$e->getMessage()."\n", 3, $log);
    }
}

class HTWFileExtract
{
    private $path_to_file;
    private $extract_path;
    private $zip;

    public function __construct(ZipArchive $zip)
    {
        $this->zip = $zip;
    }

    public function setFilePath($path_to_file)
    {
        $this->path_to_file=$path_to_file;
    }

    public function setExtractPath($extract_path)
    {
        $this->extract_path=$extract_path;
    }

    public function ExtractToPath()
    {
        if ($this->zip->open($this->path_to_file) === TRUE)
        {
            $this->zip->extractTo($this->extract_path);
        }
        else
        {
            //not a zip file
            throw new Exception('Not a zip file');
        }
    }

    public function delExtractPath($f)
    {
        if (is_dir($f)) {
            foreach(glob($f.'/*') as $sf) {
                if (is_dir($sf) && !is_link($sf)) {
                    $this->delExtractPath($sf);
                } else {
                    unlink($sf);
                }
            }
        }
        rmdir($f);
    }
}

class HTWImportCourse
{
    private $course;
    private $admin_user_id;

    public function __construct($course_data, $admin_user_id)
    {
        $this->course = create_course($course_data);
        $this->admin_user_id=$admin_user_id;
    }

    public function doRestore()
    {
        //Extracted file to be expected under moodledate/temp/backup/import
        $rc = new restore_controller("import",  $this->course->id, backup::INTERACTIVE_NO,backup::MODE_GENERAL,
            $this->admin_user_id,backup::TARGET_NEW_COURSE);
        $rc->get_logger()->set_next(new output_indented_logger(backup::LOG_INFO, false, true));
        $rc->execute_precheck(true);
        $rc->execute_plan();
    }

}
echo "\n\nBeginning massive import\n\n";

$handle=opendir($path_to_mbz);
$i=0;

while ($file = readdir ($handle)) {

   //check the filename
   if ($file==".." or $file=="." or strrchr($file, ".")!=".mbz"){
       continue;
   }

    $i++;

    echo "+++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n";
    echo "Course ".$i."-->".$file."\n";
    echo "+++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n";


  //Extract mbz to path
   try
   {
    //extract file
    $zip = new ZipArchive;
    $htwFileExtract=new HTWFileExtract($zip);
    $htwFileExtract->delExtractPath($extract_path);
    $htwFileExtract->setExtractPath($extract_path);
    $htwFileExtract->setFilePath($path_to_mbz."/".$file);
    $htwFileExtract->ExtractToPath();
    $zip->close();
   }
   catch (Exception $e)
   {
       $msg="Error: Extract --->";
       echo $msg.$e->getMessage()."\n";
       Logging::writeLog($msg,$e,$log,$i." ".$file);
       continue;
   }

  echo "Starting course import"."\n";
  //Generate course names
  $shortname = 'MBZShortFailed-' .$i."-".date('Y-m-d H:i:s');
  $fullname = 'MBZRestoreFailed-' .$i."-".date('Y-m-d H:i:s');

  //Import course
  try
  {
    $course_data=new Object();
    $course_data->category = $categoryid;
    $course_data->fullname = $fullname;
    $course_data->shortname = $shortname;

    
    $coursexmlfile=$extract_path."/course/course.xml";
    $myfile = fopen($coursexmlfile, "r") or die("Unable to open file!");
    $coursexml = fread($myfile,filesize($coursexmlfile));
    fclose($myfile);

    $pattern = "/<description>([\w\W]*?)<\/description>/";
    preg_match($pattern, $coursexml, $matches);
    $cat = $matches[1];
    $cat = preg_replace("/[^0-9]/","",$cat);
    
    if (is_numeric($cat)) {
      echo "<category>".$cat."</category>\n";
      $course_data->category = $cat;
    }

    $HTWImportCourse= new HTWImportCourse($course_data,$admin_user_id);
    $HTWImportCourse->doRestore();
  }
  catch (Exception $e)
  {
      $msg="Error: Course import --->";
      echo $msg.$e->getMessage()."\n";
      Logging::writeLog($msg,$e,$log,$i." ".$file);
      continue;
  }

  echo "Course imported:".$file."\n\n";
}
