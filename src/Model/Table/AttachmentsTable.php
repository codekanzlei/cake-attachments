<?php
namespace Attachments\Model\Table;

use Attachments\Model\Entity\Attachment;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Attachments Model
 */
class AttachmentsTable extends Table
{

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        $this->table('attachments');
        $this->displayField('filename');
        $this->primaryKey('id');
        $this->addBehavior('Timestamp');

        $this->schema()->columnType('tags', 'json');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator)
    {
        $validator
            ->allowEmpty('id', 'create')
            ->requirePresence('filepath', 'create')
            ->notEmpty('filepath')
            ->requirePresence('filename', 'create')
            ->notEmpty('filename')
            ->requirePresence('filetype', 'create')
            ->notEmpty('filetype')
            ->add('filesize', 'valid', ['rule' => 'numeric'])
            ->requirePresence('filesize', 'create')
            ->notEmpty('filesize')
            ->requirePresence('model', 'create')
            ->notEmpty('model')
            ->requirePresence('foreign_key', 'create')
            ->notEmpty('foreign_key');

        return $validator;
    }

    /**
     * Takes the array from the attachments area hidden form field and creates
     * attachment records for the given entity
     *
     * @param EntityInterface $entity Entity to attach the files to
     * @param array $uploads List of paths relative to the Attachments.tmpUploadsPath
     *                       config value or ['path_to_file' => [tag1, tag2, tag3, ...]]
     * @return void
     */
    public function addUploads(EntityInterface $entity, array $uploads)
    {
        $attachments = [];
        foreach ($uploads as $path => $tags) {
            if (!(array_keys($uploads) !== range(0, count($uploads) - 1))) {
                // if only paths and no tags
                $path = $tags;
                $tags = [];
            }
            $file = Configure::read('Attachments.tmpUploadsPath') . $path;

            $attachment = $this->createAttachmentEntity($entity, $file, $tags);
            $this->save($attachment);
            $attachments[] = $attachment;
        }
        $entity->attachments = $attachments;
    }

    /**
     * Save one Attachemnt
     *
     * @param EntityInterface $entity Entity
     * @param string $upload String to uploaded file or ['path_to_file' => [tag1, tag2, tag3, ...]]
     * @return entity
     */
    public function addUpload(EntityInterface $entity, $upload)
    {
        $tags = [];
        $path = $upload;
        if (is_array($upload)) {
            $tags = reset($upload);
            $path = reset(array_flip($upload));
        }
        $file = Configure::read('Attachments.tmpUploadsPath') . $path;
        $attachment = $this->createAttachmentEntity($entity, $file, $tags);
        $save = $this->save($attachment);
        if ($save) {
            return $attachment;
        }
        return $save;
    }
    /**
     * afterSave Event. If an attachment entity has its tmpPath value set, it will be moved
     * to the defined filepath
     *
     * @param Event $event Event
     * @param Attachment $attachment Entity
     * @param ArrayObject $options Options
     * @return void
     * @throws \Exception If the file couldn't be moved
     */
    public function afterSave(Event $event, Attachment $attachment, \ArrayObject $options)
    {
        if ($attachment->tmpPath) {
            // Make sure the folder is created
            $folder = new Folder();
            $targetDir = Configure::read('Attachments.path') . dirname($attachment->filepath);

            if (!$folder->create($targetDir)) {
                throw new \Exception("Folder {$targetDir} could not be created.");
            }
            $targetPath = Configure::read('Attachments.path') . $attachment->filepath;
            if (!rename($attachment->tmpPath, $targetPath)) {
                throw new \Exception("Temporary file {$attachment->tmpPath} could not be moved to {$attachment->filepath}");
            }
            $attachment->tmpPath = null;
        }
    }

    /**
     * afterDelete
     *
     * @param Event $event Event
     * @param Attachment $attachment Entity
     * @param ArrayObject $options Options
     * @return void
     */
    public function afterDelete(Event $event, Attachment $attachment, \ArrayObject $options)
    {
        $attachment->deleteFile();
    }

    /**
     * Creates an Attachment entity based on the given file
     *
     * @param EntityInterface $entity Entity the file will be attached to
     * @param string $filePath Absolute path to the file
     * @param array $tags Indexed array of tags to be assigned
     * @return Attachment
     * @throws \Exception If the given file doesn't exist or isn't readable
     */
    public function createAttachmentEntity(EntityInterface $entity, $filePath, array $tags = [])
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File {$filePath} does not exist.");
        }
        if (!is_readable($filePath)) {
            throw new \Exception("File {$filePath} cannot be read.");
        }
        $file = new File($filePath);
        $info = $file->info();

        if (!empty($invalidChars = Configure::read('Attachments.invalid_characters'))) {
            $replaceChars = Configure::read('Attachments.replace_characters');
            $info['basename'] = str_replace($invalidChars, $replaceChars ? $replaceChars : '', $info['basename']);
            $info['filename'] = str_replace($invalidChars, $replaceChars ? $replaceChars : '', $info['filename']);
        }

        // in filepath, we store the path relative to the Attachment.path configuration
        // to make it easy to switch storage
        $info = $this->__getFileName($info, $entity);
        $targetPath = $entity->source() . '/' . $entity->id . '/' . $info['basename'];

        $attachment = $this->newEntity([
            'model' => $entity->source(),
            'foreign_key' => $entity->id,
            'filename' => $info['basename'],
            'filesize' => $info['filesize'],
            'filetype' => $info['mime'],
            'filepath' => $targetPath,
            'tmpPath' => $filePath,
            'tags' => $tags
        ]);
        return $attachment;
    }

    /**
     * recursive method to increase the filename in case the file already exists
     *
     * @param string $fileInfo Array of information about the file
     * @param EntityInterface $entity Entity
     * @param string $id counter varibale to extend the filename
     * @return array
     */
    private function __getFileName($fileInfo, EntityInterface $entity, $id = 0)
    {
        if (!file_exists(Configure::read('Attachments.path') . $entity->source() . '/' . $entity->id . '/' . $fileInfo['basename'])) {
            return $fileInfo;
        }
        $fileInfo['basename'] = $fileInfo['filename'] . ' (' . ++$id . ').' . $fileInfo['extension'];
        return $this->__getFileName($fileInfo, $entity, $id);
    }
}
