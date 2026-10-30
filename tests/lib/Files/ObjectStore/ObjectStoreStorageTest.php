<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Test\Files\ObjectStore;

use Aws\Multipart\UploadState;
use Aws\Result;
use Aws\S3\Exception\S3MultipartUploadException;
use OC\Files\ObjectStore\StorageObjectStore;
use OC\Files\Storage\Temporary;
use OC\Files\Storage\Wrapper\Jail;
use OC\Files\Storage\Wrapper\Quota;
use OCP\Constants;
use OCP\Files\GenericFileException;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\Files\ObjectStore\IObjectStoreMultiPartUpload;
use Test\Files\Storage\Storage;

#[\PHPUnit\Framework\Attributes\Group('DB')]
class ObjectStoreStorageTest extends Storage {
	/** @var ObjectStoreStorageOverwrite */
	protected $instance;

	/**
	 * @var IObjectStore
	 */
	private $objectStorage;

	private Temporary $baseStorage;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->baseStorage = new Temporary();
		$this->objectStorage = new StorageObjectStore($this->baseStorage);
		$config['objectstore'] = $this->objectStorage;
		$this->instance = new ObjectStoreStorageOverwrite($config);
	}

	#[\Override]
	protected function tearDown(): void {
		if (is_null($this->instance)) {
			return;
		}
		$this->instance->getCache()->clear();

		parent::tearDown();
	}

	#[\Override]
	public function testStat(): void {
		$textFile = \OC::$SERVERROOT . '/tests/data/lorem.txt';
		$ctimeStart = time();
		$this->instance->file_put_contents('/lorem.txt', file_get_contents($textFile));
		$this->assertTrue($this->instance->isReadable('/lorem.txt'));
		$ctimeEnd = time();
		$mTime = $this->instance->filemtime('/lorem.txt');

		// check that ($ctimeStart - 5) <= $mTime <= ($ctimeEnd + 1)
		$this->assertGreaterThanOrEqual(($ctimeStart - 5), $mTime);
		$this->assertLessThanOrEqual(($ctimeEnd + 1), $mTime);
		$this->assertEquals(filesize($textFile), $this->instance->filesize('/lorem.txt'));

		$stat = $this->instance->stat('/lorem.txt');
		//only size and mtime are required in the result
		$this->assertEquals($stat['size'], $this->instance->filesize('/lorem.txt'));
		$this->assertEquals($stat['mtime'], $mTime);

		if ($this->instance->touch('/lorem.txt', 100) !== false) {
			$mTime = $this->instance->filemtime('/lorem.txt');
			$this->assertEquals($mTime, 100);
		}
	}

	#[\Override]
	public function testCheckUpdate(): void {
		$this->markTestSkipped('Detecting external changes is not supported on object storages');
	}

	#[\Override]
	#[\PHPUnit\Framework\Attributes\DataProvider('copyAndMoveProvider')]
	public function testMove($source, $target): void {
		$this->initSourceAndTarget($source);
		$sourceId = $this->instance->getCache()->getId(ltrim($source, '/'));
		$this->assertNotEquals(-1, $sourceId);

		$this->instance->rename($source, $target);

		$this->assertTrue($this->instance->file_exists($target), $target . ' was not created');
		$this->assertFalse($this->instance->file_exists($source), $source . ' still exists');
		$this->assertSameAsLorem($target);

		$targetId = $this->instance->getCache()->getId(ltrim($target, '/'));
		$this->assertSame($sourceId, $targetId, 'fileid must be stable on move or shares will break');
	}

	#[\Override]
	public function testRenameDirectory(): void {
		$this->instance->mkdir('source');
		$this->instance->file_put_contents('source/test1.txt', 'foo');
		$this->instance->file_put_contents('source/test2.txt', 'qwerty');
		$this->instance->mkdir('source/subfolder');
		$this->instance->file_put_contents('source/subfolder/test.txt', 'bar');
		$sourceId = $this->instance->getCache()->getId('source');
		$this->assertNotEquals(-1, $sourceId);
		$this->instance->rename('source', 'target');

		$this->assertFalse($this->instance->file_exists('source'));
		$this->assertFalse($this->instance->file_exists('source/test1.txt'));
		$this->assertFalse($this->instance->file_exists('source/test2.txt'));
		$this->assertFalse($this->instance->file_exists('source/subfolder'));
		$this->assertFalse($this->instance->file_exists('source/subfolder/test.txt'));

		$this->assertTrue($this->instance->file_exists('target'));
		$this->assertTrue($this->instance->file_exists('target/test1.txt'));
		$this->assertTrue($this->instance->file_exists('target/test2.txt'));
		$this->assertTrue($this->instance->file_exists('target/subfolder'));
		$this->assertTrue($this->instance->file_exists('target/subfolder/test.txt'));

		$this->assertEquals('foo', $this->instance->file_get_contents('target/test1.txt'));
		$this->assertEquals('qwerty', $this->instance->file_get_contents('target/test2.txt'));
		$this->assertEquals('bar', $this->instance->file_get_contents('target/subfolder/test.txt'));
		$targetId = $this->instance->getCache()->getId('target');
		$this->assertSame($sourceId, $targetId, 'fileid must be stable on move or shares will break');
	}

	#[\Override]
	public function testRenameOverWriteDirectory(): void {
		$this->instance->mkdir('source');
		$this->instance->file_put_contents('source/test1.txt', 'foo');
		$sourceId = $this->instance->getCache()->getId('source');
		$this->assertNotEquals(-1, $sourceId);

		$this->instance->mkdir('target');
		$this->instance->file_put_contents('target/test1.txt', 'bar');
		$this->instance->file_put_contents('target/test2.txt', 'bar');

		$this->instance->rename('source', 'target');

		$this->assertFalse($this->instance->file_exists('source'));
		$this->assertFalse($this->instance->file_exists('source/test1.txt'));
		$this->assertFalse($this->instance->file_exists('target/test2.txt'));
		$this->assertEquals('foo', $this->instance->file_get_contents('target/test1.txt'));
		$targetId = $this->instance->getCache()->getId('target');
		$this->assertSame($sourceId, $targetId, 'fileid must be stable on move or shares will break');
	}

	#[\Override]
	public function testRenameOverWriteDirectoryOverFile(): void {
		$this->instance->mkdir('source');
		$this->instance->file_put_contents('source/test1.txt', 'foo');
		$sourceId = $this->instance->getCache()->getId('source');
		$this->assertNotEquals(-1, $sourceId);

		$this->instance->file_put_contents('target', 'bar');

		$this->instance->rename('source', 'target');

		$this->assertFalse($this->instance->file_exists('source'));
		$this->assertFalse($this->instance->file_exists('source/test1.txt'));
		$this->assertEquals('foo', $this->instance->file_get_contents('target/test1.txt'));
		$targetId = $this->instance->getCache()->getId('target');
		$this->assertSame($sourceId, $targetId, 'fileid must be stable on move or shares will break');
	}

	public function testWriteObjectSilentFailure(): void {
		$objectStore = $this->instance->getObjectStore();
		$this->instance->setObjectStore(new FailWriteObjectStore($objectStore));

		try {
			$this->instance->file_put_contents('test.txt', 'foo');
			$this->fail('expected exception');
		} catch (\Exception $e) {
			$this->assertStringStartsWith('Object not found after writing', $e->getMessage());
		}
		$this->assertFalse($this->instance->file_exists('test.txt'));
	}

	public function testWriteObjectSilentFailureNoCheck(): void {
		$objectStore = $this->instance->getObjectStore();
		$this->instance->setObjectStore(new FailWriteObjectStore($objectStore));
		$this->instance->setValidateWrites(false);

		$this->instance->file_put_contents('test.txt', 'foo');
		$this->assertTrue($this->instance->file_exists('test.txt'));
	}

	public function testChunkedOverwritesRefreshMetadataAndPropagateQuotaSizeDeltas(): void {
		$multipartStore = new class($this->baseStorage) extends StorageObjectStore implements IObjectStoreMultiPartUpload {
			private array $parts = [];
			public bool $failNextCompletion = false;
			public int $abortCount = 0;
			public array $completedPartNumbers = [];

			public function initiateMultipartUpload(string $urn): string {
				return 'upload-token';
			}

			public function uploadMultipartPart(
				string $urn,
				string $uploadId,
				int $partId,
				$stream,
				$size,
			): Result {
				$data = stream_get_contents($stream);
				$this->parts[$urn][$uploadId][$partId] = $data;
				return new Result(['ETag' => '"' . md5($data) . '"']);
			}

			public function completeMultipartUpload(string $urn, string $uploadId, array $result): int {
				if ($this->failNextCompletion) {
					$this->failNextCompletion = false;
					throw new S3MultipartUploadException(new UploadState(['Key' => $urn]));
				}
				$this->completedPartNumbers = array_column($result, 'PartNumber');
				$data = '';
				foreach ($result as $part) {
					$data .= $this->parts[$urn][$uploadId][$part['PartNumber']];
				}
				$stream = fopen('php://temp', 'w+b');
				fwrite($stream, $data);
				rewind($stream);
				$this->writeObject($urn, $stream);
				fclose($stream);
				unset($this->parts[$urn][$uploadId]);
				return strlen($data);
			}

			public function abortMultipartUpload(string $urn, string $uploadId): void {
				$this->abortCount++;
				unset($this->parts[$urn][$uploadId]);
			}

			public function getMultipartUploads(string $urn, string $uploadId): array {
				$uploads = [];
				foreach ($this->parts[$urn][$uploadId] ?? [] as $partId => $data) {
					$uploads[$partId] = [
						'PartNumber' => $partId,
						'ETag' => '"' . md5($data) . '"',
						'Size' => strlen($data),
					];
				}
				return $uploads;
			}
		};

		$this->instance->setObjectStore($multipartStore);
		$this->instance->mkdir('folder');
		$this->instance->file_put_contents('folder/target.txt', 'old data');
		$fileCache = $this->instance->getCache();
		$file = $fileCache->get('folder/target.txt');
		$oldSize = $file->getSize();
		$fileCache->update($file->getId(), [
			'mtime' => 1,
			'storage_mtime' => 1,
			'checksum' => 'old-checksum',
			'unencrypted_size' => $oldSize,
		]);
		$file = $fileCache->get('folder/target.txt');
		$oldEtag = $file->getEtag();
		$oldMtime = $file->getMTime();
		$oldStorageMtime = $file->getStorageMTime();
		$folder = $fileCache->get('folder');
		$root = $fileCache->get('');
		$fileCache->update($folder->getId(), ['size' => $oldSize]);
		$fileCache->update($root->getId(), ['size' => $oldSize]);
		$quota = new Quota(['storage' => $this->instance, 'quota' => 20, 'root' => '']);
		$this->assertSame(12, $quota->free_space('folder'));

		$uploadId = $this->instance->startChunkedWrite('folder/target.txt');
		foreach ([2 => 'content', 1 => 'new-'] as $partId => $data) {
			$part = fopen('php://temp', 'w+b');
			fwrite($part, $data);
			rewind($part);
			$this->instance->putChunkedWritePart('folder/target.txt', $uploadId, (string)$partId, $part, strlen($data));
			fclose($part);
		}

		// Uploaded multipart parts must not alter the cached, still-committed file or quota usage.
		$this->assertSame($oldSize, $fileCache->get('folder/target.txt')->getSize());
		$this->assertSame($oldSize, $fileCache->get('folder')->getSize());
		$this->assertSame($oldSize, $fileCache->get('')->getSize());
		$this->assertSame(12, $quota->free_space('folder'));

		$this->assertSame(11, $this->instance->completeChunkedWrite('folder/target.txt', $uploadId));

		$file = $fileCache->get('folder/target.txt');
		$this->assertSame(11, $file->getSize());
		$this->assertNotSame($oldEtag, $file->getEtag());
		$this->assertGreaterThan(0, $file->getMTime());
		$this->assertNotSame($oldMtime, $file->getMTime());
		$this->assertNotSame($oldStorageMtime, $file->getStorageMTime());
		$this->assertSame($file->getMTime(), $file->getStorageMTime());
		$this->assertSame(11, $file['unencrypted_size']);
		$this->assertSame('', $file['checksum']);
		$this->assertSame('new-content', $this->instance->file_get_contents('folder/target.txt'));
		$this->assertSame(11, $fileCache->get('folder')->getSize());
		$this->assertSame(11, $fileCache->get('')->getSize());
		$this->assertSame(9, $quota->free_space('folder'));
		$this->assertSame([1, 2], $multipartStore->completedPartNumbers);

		$etag = $file->getEtag();
		$uploadId = $this->instance->startChunkedWrite('folder/target.txt');
		$part = fopen('php://temp', 'w+b');
		fwrite($part, 'tiny');
		rewind($part);
		$this->instance->putChunkedWritePart('folder/target.txt', $uploadId, '1', $part, 4);
		fclose($part);
		$this->instance->completeChunkedWrite('folder/target.txt', $uploadId);
		$file = $fileCache->get('folder/target.txt');
		$this->assertSame(4, $file->getSize());
		$this->assertNotSame($etag, $file->getEtag());
		$this->assertSame(4, $fileCache->get('folder')->getSize());
		$this->assertSame(4, $fileCache->get('')->getSize());
		$this->assertSame(16, $quota->free_space('folder'));

		$etag = $file->getEtag();
		$uploadId = $this->instance->startChunkedWrite('folder/target.txt');
		$part = fopen('php://temp', 'w+b');
		fwrite($part, 'more');
		rewind($part);
		$this->instance->putChunkedWritePart('folder/target.txt', $uploadId, '1', $part, 4);
		fclose($part);
		$this->instance->completeChunkedWrite('folder/target.txt', $uploadId);
		$file = $fileCache->get('folder/target.txt');
		$this->assertSame(4, $file->getSize());
		$this->assertNotSame($etag, $file->getEtag());
		$this->assertSame(4, $fileCache->get('folder')->getSize());
		$this->assertSame(16, $quota->free_space('folder'));

		$uploadId = $this->instance->startChunkedWrite('folder/target.txt');
		$part = fopen('php://temp', 'w+b');
		fwrite($part, 'abandoned');
		rewind($part);
		$this->instance->putChunkedWritePart('folder/target.txt', $uploadId, '1', $part, 9);
		fclose($part);
		$this->assertSame(4, $fileCache->get('folder/target.txt')->getSize());
		$this->assertSame(4, $fileCache->get('folder')->getSize());
		$this->assertSame(16, $quota->free_space('folder'));
		$this->instance->cancelChunkedWrite('folder/target.txt', $uploadId);
		$this->assertSame(1, $multipartStore->abortCount);
		$this->assertSame(4, $fileCache->get('folder/target.txt')->getSize());
		$this->assertSame('more', $this->instance->file_get_contents('folder/target.txt'));

		$uploadId = $this->instance->startChunkedWrite('folder/target.txt');
		$part = fopen('php://temp', 'w+b');
		fwrite($part, 'failed');
		rewind($part);
		$this->instance->putChunkedWritePart('folder/target.txt', $uploadId, '1', $part, 6);
		fclose($part);
		$multipartStore->failNextCompletion = true;
		try {
			$this->instance->completeChunkedWrite('folder/target.txt', $uploadId);
			$this->fail('Expected multipart completion to fail');
		} catch (GenericFileException $e) {
			$this->assertSame('Could not write chunked file', $e->getMessage());
		}
		$this->assertSame(2, $multipartStore->abortCount);
		$this->assertSame(4, $fileCache->get('folder/target.txt')->getSize());
		$this->assertSame(4, $fileCache->get('folder')->getSize());
		$this->assertSame(4, $fileCache->get('')->getSize());
		$this->assertSame(16, $quota->free_space('folder'));
		$this->assertSame('more', $this->instance->file_get_contents('folder/target.txt'));
	}

	public function testDeleteObjectFailureKeepCache(): void {
		$objectStore = $this->instance->getObjectStore();
		$this->instance->setObjectStore(new FailDeleteObjectStore($objectStore));
		$cache = $this->instance->getCache();

		$this->instance->file_put_contents('test.txt', 'foo');

		$this->assertTrue($cache->inCache('test.txt'));

		$this->assertFalse($this->instance->unlink('test.txt'));

		$this->assertTrue($cache->inCache('test.txt'));

		$this->instance->mkdir('foo');
		$this->instance->file_put_contents('foo/test.txt', 'foo');

		$this->assertTrue($cache->inCache('foo'));
		$this->assertTrue($cache->inCache('foo/test.txt'));

		$this->instance->rmdir('foo');

		$this->assertTrue($cache->inCache('foo'));
		$this->assertTrue($cache->inCache('foo/test.txt'));
	}

	public function testCopyBetweenJails(): void {
		$this->instance->mkdir('a');
		$this->instance->mkdir('b');
		$jailA = new Jail([
			'storage' => $this->instance,
			'root' => 'a'
		]);
		$jailB = new Jail([
			'storage' => $this->instance,
			'root' => 'b'
		]);
		$jailA->mkdir('sub');
		$jailA->file_put_contents('1.txt', '1');
		$jailA->file_put_contents('sub/2.txt', '2');
		$jailA->file_put_contents('sub/3.txt', '3');

		$jailB->copyFromStorage($jailA, '', 'target');

		$this->assertEquals('1', $this->instance->file_get_contents('b/target/1.txt'));
		$this->assertEquals('2', $this->instance->file_get_contents('b/target/sub/2.txt'));
		$this->assertEquals('3', $this->instance->file_get_contents('b/target/sub/3.txt'));
	}

	public function testCopyGrantsPermissions(): void {
		$config['objectstore'] = $this->objectStorage;
		$instance = new ObjectStoreStorageOverwrite($config);

		$cache = $instance->getCache();

		$instance->file_put_contents('test.txt', 'foo');
		$this->assertTrue($cache->inCache('test.txt'));

		$cache->update($cache->getId('test.txt'), ['permissions' => Constants::PERMISSION_READ]);
		$this->assertEquals(Constants::PERMISSION_READ, $instance->getPermissions('test.txt'));

		$this->assertTrue($instance->copy('test.txt', 'new.txt'));

		$this->assertTrue($cache->inCache('new.txt'));
		$this->assertEquals(Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE, $instance->getPermissions('new.txt'));
	}

	public function testCopyFolderSize(): void {
		$cache = $this->instance->getCache();

		$this->instance->mkdir('source');
		$this->instance->file_put_contents('source/test.txt', 'foo');
		$this->instance->getUpdater()->update('source/test.txt');
		$this->assertEquals(3, $cache->get('source')->getSize());

		$this->assertTrue($this->instance->copy('source', 'target'));

		$this->assertEquals(3, $cache->get('target')->getSize());
	}

	public function testFopenWriteInDottedDirectoryWithoutExtension(): void {
		$dir = '0. Folder.With.Dots/Subfolder';
		$this->instance->mkdir($dir);

		$filePath = $dir . '/extensionlessfile';
		$handle = $this->instance->fopen($filePath, 'w');
		$this->assertIsResource($handle);

		fwrite($handle, 'sample content');
		fclose($handle);

		$this->assertTrue($this->instance->file_exists($filePath));
		$this->assertEquals('sample content', $this->instance->file_get_contents($filePath));
	}
}
