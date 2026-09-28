<?php
namespace App\Support;

// Choose once per PHP request; backend pages expose no session-demo mutations.
if (config('school.backend')) {
    abstract class SchoolPage extends BackendPage {}
} else {
    abstract class SchoolPage extends DemoPage {}
}
