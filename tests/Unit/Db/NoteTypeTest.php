<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Db;

use OCA\CrmNotes\Db\NoteType;
use PHPUnit\Framework\TestCase;

class NoteTypeTest extends TestCase {

    private NoteType $noteType;

    protected function setUp(): void {
        $this->noteType = new NoteType();
    }

    public function testSetAndGetName(): void {
        $this->noteType->setName('Call');
        $this->assertSame('Call', $this->noteType->getName());
    }

    public function testSetAndGetIcon(): void {
        $this->noteType->setIcon('icon-phone');
        $this->assertSame('icon-phone', $this->noteType->getIcon());
    }

    public function testSetAndGetColor(): void {
        $this->noteType->setColor('#2ecc71');
        $this->assertSame('#2ecc71', $this->noteType->getColor());
    }

    public function testSetAndGetUserId(): void {
        $this->noteType->setUserId('admin');
        $this->assertSame('admin', $this->noteType->getUserId());
    }

    public function testSetAndGetIsDefault(): void {
        $this->noteType->setIsDefault(true);
        $this->assertTrue($this->noteType->getIsDefault());
    }

    public function testDefaultValues(): void {
        $this->assertSame('', $this->noteType->getName());
        $this->assertSame('icon-note', $this->noteType->getIcon());
        $this->assertSame('#0082c9', $this->noteType->getColor());
        $this->assertSame('', $this->noteType->getUserId());
        $this->assertFalse($this->noteType->getIsDefault());
    }

    public function testJsonSerialize(): void {
        $this->noteType->setId(42);
        $this->noteType->setName('Meeting');
        $this->noteType->setIcon('icon-calendar');
        $this->noteType->setColor('#3498db');
        $this->noteType->setUserId('admin');
        $this->noteType->setIsDefault(true);

        $json = $this->noteType->jsonSerialize();

        $this->assertSame(42, $json['id']);
        $this->assertSame('Meeting', $json['name']);
        $this->assertSame('icon-calendar', $json['icon']);
        $this->assertSame('#3498db', $json['color']);
        $this->assertSame('admin', $json['userId']);
        $this->assertTrue($json['isDefault']);
    }

    public function testJsonSerializeKeys(): void {
        $json = $this->noteType->jsonSerialize();
        $expectedKeys = ['id', 'name', 'icon', 'color', 'userId', 'isDefault'];
        $this->assertSame($expectedKeys, array_keys($json));
    }

    public function testFromParams(): void {
        $noteType = NoteType::fromParams([
            'name' => 'Task',
            'icon' => 'icon-checkmark',
            'color' => '#e67e22',
            'userId' => 'user1',
            'isDefault' => false,
        ]);

        $this->assertSame('Task', $noteType->getName());
        $this->assertSame('icon-checkmark', $noteType->getIcon());
        $this->assertSame('#e67e22', $noteType->getColor());
        $this->assertSame('user1', $noteType->getUserId());
        $this->assertFalse($noteType->getIsDefault());
    }

    public function testSetId(): void {
        $this->noteType->setId(99);
        $this->assertSame(99, $this->noteType->getId());
    }

    public function testFieldTypesRegistered(): void {
        $types = $this->noteType->getFieldTypes();
        $this->assertArrayHasKey('id', $types);
        $this->assertArrayHasKey('name', $types);
        $this->assertArrayHasKey('icon', $types);
        $this->assertArrayHasKey('color', $types);
        $this->assertArrayHasKey('userId', $types);
        $this->assertArrayHasKey('isDefault', $types);
    }

    public function testUpdatedFieldsTracking(): void {
        $this->noteType->resetUpdatedFields();
        $this->assertEmpty($this->noteType->getUpdatedFields());

        $this->noteType->setName('Updated');
        $updated = $this->noteType->getUpdatedFields();
        $this->assertArrayHasKey('name', $updated);
    }

    public function testSetNullIcon(): void {
        $this->noteType->setIcon(null);
        $this->assertNull($this->noteType->getIcon());
    }

    public function testSetNullColor(): void {
        $this->noteType->setColor(null);
        $this->assertNull($this->noteType->getColor());
    }
}
