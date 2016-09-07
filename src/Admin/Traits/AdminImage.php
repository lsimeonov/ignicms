<?php

namespace Despark\Cms\Admin\Traits;

use Despark\Cms\Admin\Observers\ImageObserver;
use Despark\Cms\Exceptions\ModelSanityException;
use Despark\Cms\Models\AdminModel;
use Despark\Cms\Models\Image as ImageModel;
use File;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Image;

/**
 * Class AdminImage.
 */
trait AdminImage
{
    /**
     * @var array Cache of generated thumb paths
     */
    protected $thumbnailPaths;

    /**
     * @var int|null|false Retina factor
     */
    protected $retinaFactor = 2;

    /**
     * @var string
     */
    protected $currentUploadDir;

    /**
     * @var string
     */
    public $uploadDir = 'uploads';

    /**
     * @return mixed
     */
    public function images()
    {
        /* @var Model $this */
        return $this->morphMany(ImageModel::class, 'image', 'resource_model', 'resource_id');
    }

    /**
     * Boot the trait.
     */
    public static function bootAdminImage()
    {
        // Observer for the model
        static::observe(ImageObserver::class);
        // We need to listen for booted event and modify the model.
        \Event::listen('eloquent.booted: '.static::class, [new self, 'bootstrapModel']);
    }

    /**
     * @param $model
     * @throws ModelSanityException
     */
    public function bootstrapModel($model)
    {
        if (! property_exists($model, 'rules')) {
            throw new ModelSanityException('Missing rules property for model '.get_class($model));
        }

        if (! $model instanceof AdminModel) {
            throw new ModelSanityException('Model '.get_class($model).' must be instanceof '.AdminModel::class);
        }

        $imageFields = $model->getImageFields();

        if (! is_array($imageFields)) {
            throw new ModelSanityException('No Image fields defined in config for model '.get_class($model));
        }

        $this->retinaFactor = config('ignicms.images.retina_factor');
        if ($this->retinaFactor) {
            foreach ($imageFields as $fieldName => $field) {
                // Calculate minimum allowed image size.
                list($minWidth, $minHeight) = $model->getMinAllowedImageSize($field);
                $restrictions = [];
                if ($minWidth) {
                    $restrictions[] = 'min_width='.$minWidth;
                }
                if ($minHeight) {
                    $restrictions[] = 'min_height='.$minHeight;
                }
                // Prepare model rules.
                if (isset($model->rules[$fieldName])) {
                    $rules = explode('|', $model->rules[$fieldName]);
                    if (strstr('max:', $model->rules[$fieldName]) === false) {
                        $rules[] = 'max:'.config('ignicms.images.max_upload_size');
                        $model->rules[$fieldName] = 'dimensions:'.implode(',', $restrictions).
                            '|max:'.config('ignicms.images.max_upload_size');
                    }
                    // Check to see for dimensions rule and remove it.
                    if (strstr('dimensions:', $model->rules[$fieldName]) !== false) {
                        foreach ($rules as $key => $rule) {
                            if (strstr('dimensions:', $rule) !== false) {
                                unset($rules[$key]);
                            }
                        }
                    }
                    $rules[] = 'dimensions:'.implode(',', $restrictions);
                    $model->rules[$fieldName] = implode('|', $rules);
                } else {
                    $model->rules[$fieldName] = 'max:'.config('ignicms.images.max_upload_size').
                        '|dimensions:'.implode(',', $restrictions);
                }
            }
        }
    }

    /**
     * Save Image.
     */
    public function saveImages()
    {
        $imageFields = $this->getImageFields();

        foreach ($imageFields as $imageType => $options) {
            if ($file = array_get($this->files, $imageType)) {

                // First delete unused images
                foreach ($this->getImagesOfType($imageType) as $image) {
                    $image->delete();
                }

                $images = $this->manipulateImage($file, $options);

                // We will save just the source one as a relation.
                /** @var \Illuminate\Http\File $sourceFile */
                $sourceFile = $images['original']['source'];

                $imageModel = new ImageModel([
                    'original_image' => $sourceFile->getFilename(),
                    'retina_factor' => $this->retinaFactor === false ? null : $this->retinaFactor,
                    'image_type' => $imageType,
                ]);
                unset($this->attributes[$imageType]);
                $this->images()->save($imageModel);
            }
        }
    }

    /**
     * @param UploadedFile $file
     * @param array $options
     * @return array
     */
    public function manipulateImage(UploadedFile $file, array $options)
    {
        $images = [];
        $sanitizedFilename = $this->sanitizeFilename($file->getClientOriginalName());
        $pathParts = pathinfo($sanitizedFilename);
        // Move uploaded file and rename it as source file.
        $filename = $pathParts['filename'].'_source.'.$pathParts['extension'];
        // We need to generate unique name if the name is already in use.

        $sourceFile = $file->move($this->getThumbnailPath(), $filename);
        $images['original']['source'] = $sourceFile;


        // If we have retina factor
        if ($this->retinaFactor) {
            // Generate retina image by just copying the source.
            $retinaFilename = $this->generateRetinaName($sanitizedFilename);
            File::copy($sourceFile->getRealPath(), $this->getThumbnailPath().$retinaFilename);
            $images['original']['retina'] = Image::make($this->getThumbnailPath().$retinaFilename);

            // The original image is scaled down version of the source.
            $originalImage = Image::make($sourceFile->getRealPath());
            $width = round($originalImage->getWidth() / $this->retinaFactor);
            $height = round($originalImage->getHeight() / $this->retinaFactor);
            $originalImage->resize($width, $height);
            $images['original']['original_file'] = $originalImage->save($this->getThumbnailPath().$sanitizedFilename);

            // Generate thumbs
            foreach ($options['thumbnails'] as $thumbnailName => $thumbnailOptions) {
                // Create retina thumb
                $images['thumbnails'][$thumbnailName]['retina'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $this->generateRetinaName($sanitizedFilename),
                    $thumbnailOptions['width'] * $this->retinaFactor, $thumbnailOptions['height'] * $this->retinaFactor,
                    $thumbnailOptions['type']);
                // Create original thumb
                $images['thumbnails'][$thumbnailName]['original'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $sanitizedFilename, $thumbnailOptions['width'],
                    $thumbnailOptions['height'], $thumbnailOptions['type']);
            }
        } else {
            // Copy source file.
            $filename = $this->sanitizeFilename($file->getClientOriginalName());
            File::copy($sourceFile->getRealPath(), $this->getThumbnailPath().$filename);
            $images['original']['original_file'] = Image::make($this->getThumbnailPath().$filename);

            // Generate thumbs
            foreach ($options['thumbnails'] as $thumbnailName => $thumbnailOptions) {
                // Create original thumb
                $images['thumbnails'][$thumbnailName]['original'] = $this->createThumbnail($sourceFile->getRealPath(),
                    $thumbnailName, $sanitizedFilename, $thumbnailOptions['width'],
                    $thumbnailOptions['height'], $thumbnailOptions['type']);
            }
        }

        return $images;
    }

    /**
     * @param string $sourceImagePath Source image path
     * @param string $thumbName Thumbnail name
     * @param $newFileName
     * @param null $width Desired width for resize
     * @param null $height Desired height for resize
     * @param string $resizeType Resize type
     * @return \Intervention\Image\Image
     */
    public function createThumbnail(
        $sourceImagePath,
        $thumbName,
        $newFileName,
        $width = null,
        $height = null,
        $resizeType = 'crop'
    ) {
        $image = Image::make($sourceImagePath);

        $width = ! $width ? null : $width;
        $height = ! $height ? null : $height;

        switch ($resizeType) {
            case 'crop':
                $image->fit($width, $height);
                break;

            case 'resize':
                $image->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                break;
        }

        $thumbnailPath = $this->getThumbnailPath($thumbName);

        if (! File::isDirectory($thumbnailPath)) {
            File::makeDirectory($thumbnailPath);
        }

        return $image->save($thumbnailPath.$newFileName);
    }

    /**
     * @param $type
     * @return Collection
     * @throws \Exception
     */
    public function getImagesOfType($type)
    {
        // Check to see if type is here.
        if (! in_array($type, $this->getImageTypes())) {
            throw new \Exception('Type not found in model '.self::class);
        }

        return $this->images()->where('image_type', '=', $type)->get();
    }

    /**
     * @param $filename
     * @return string
     */
    protected function sanitizeFilename($filename)
    {
        $pathParts = pathinfo($filename);

        return str_slug($pathParts['filename']).'.'.filter_var(strtolower($pathParts['extension']));
    }

    /**
     * @param $filename
     * @return string
     */
    public function generateRetinaName($filename)
    {
        $pathParts = pathinfo($filename);

        return $pathParts['filename'].'@2x.'.$pathParts['extension'];
    }

    /**
     * @param string $thumbnailType
     * @return string
     */
    public function getThumbnailPath($thumbnailType = 'original')
    {
        if (! isset($this->thumbnailPaths[$thumbnailType])) {
            $this->thumbnailPaths[$thumbnailType] = $this->getCurrentUploadDir().$thumbnailType.DIRECTORY_SEPARATOR;
        }

        return $this->thumbnailPaths[$thumbnailType];
    }

    /**
     * @param        $fieldName
     * @param string $thumbnailType
     * @return bool|string
     */
    public function getImageThumbnailPath($fieldName, $thumbnailType = 'original')
    {
        $modelImageFields = $this->getImageFields();

        if (! array_key_exists($fieldName, $modelImageFields)) {
            return false;
        }

        if (! array_key_exists($thumbnailType, $modelImageFields[$fieldName]['thumbnails'])) {
            $thumbnailType = 'original';
        }

        return $this->getThumbnailPath($thumbnailType).$this->$fieldName;
    }

    /**
     * @return array|null
     */
    public function getImageFields()
    {
        return config('admin.'.$this->identifier.'.image_fields');
    }

    /**
     * @return array
     */
    public function getImageTypes()
    {
        return array_keys($this->getImageFields());
    }

    /**
     * @param $field
     * @return array
     * @throws \Exception
     */
    public function getMinAllowedImageSize($field)
    {
        if (is_string($field)) {
            if (isset($this->getImageFields()[$field])) {
                $field = $this->getImageFields()[$field];
            } else {
                throw new \Exception('Field information missing');
            }
        }

        $minWidth = 0;
        $minHeight = 0;
        foreach ($field['thumbnails'] as $thumbnail) {
            $minWidth = $thumbnail['width'] > $minWidth ? $thumbnail['width'] : $minWidth;
            $minHeight = $thumbnail['height'] > $minHeight ? $thumbnail['height'] : $minHeight;
        }

        $factor = $this->retinaFactor ? $this->retinaFactor : 1;

        return [$minWidth * $factor, $minHeight * $factor];
    }

    /**
     * @return array|mixed|string
     */
    public function getCurrentUploadDir()
    {
        if (! isset($this->currentUploadDir)) {
            $modelDir = explode('Models', get_class($this));
            $modelDir = str_replace('\\', '_', $modelDir[1]);
            $modelDir = ltrim($modelDir, '_');
            $modelDir = strtolower($modelDir);

            $this->currentUploadDir = $this->uploadDir.DIRECTORY_SEPARATOR.$modelDir.
                DIRECTORY_SEPARATOR.$this->getKey().DIRECTORY_SEPARATOR;
        }

        return $this->currentUploadDir;
    }

    /**
     * @return bool
     */
    public function hasImages($type = null)
    {
        if ($type) {
            return $this->images()->where('image_type', '=', $type)->exists();
        }

        return (bool) count($this->images);
    }

    /**
     * @param null $type
     * @return mixed
     */
    public function getImages($type = null)
    {
        if ($type) {
            return $this->images()->where('image_type', '=', $type)->get();
        }

        return $this->images;
    }
}
